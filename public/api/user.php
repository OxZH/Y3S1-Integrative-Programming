<?php
/**
 * MODULE 2 - User Authentication & Profile Management
 * Exposed web service.  Author: Ivan Lim Tze Yang
 *
 * Replaces the getUserContactInfo half of api/stub.php. Module 1 already calls
 * this function; only the url in config.php changed.
 *
 * ---------------------------------------------------------------------------
 *  INTERFACE AGREEMENT (IFA)
 * ---------------------------------------------------------------------------
 *  Web service mechanism : RESTful, JSON over HTTP POST
 *  Source Module         : User Authentication & Profile Management
 *  Target Module         : Event & Facility Management, Venue Booking &
 *                          Payment, Social Networking & Review System,
 *                          Discovery & Event Matchmaking
 *  URL                   : http://<host>/api/user.php
 *
 *  FUNCTION: getUserContactInfo
 *    Description : Retrieves an account's public contact details by id.
 *
 *    Request
 *    | Field Name  | Field Type | Mandatory | Description             | Format               |
 *    |-------------|------------|-----------|-------------------------|----------------------|
 *    | requestId   | String     | Mandatory | Unique id of the call   | UUID, <= 36 chars    |
 *    | timeStamp   | String     | Mandatory | Time the request was made | YYYY-MM-DD HH:MM:SS |
 *    | function    | String     | Mandatory | getUserContactInfo      |                      |
 *    | baseUserId  | String     | Mandatory | Id of the account       | UUID / seed id       |
 *    | queryFlag   | Integer    | Optional  | 1 contact, 2 profile, 3 both | default 3       |
 *
 *    Response
 *    | Field Name    | Field Type | Mandatory | Description                | Format              |
 *    |---------------|------------|-----------|----------------------------|---------------------|
 *    | status        | String     | Mandatory | Result of the request      | S / F / E           |
 *    | requestId     | String     | Mandatory | Echo of the request id     |                     |
 *    | timeStamp     | String     | Mandatory | Time the response was made | YYYY-MM-DD HH:MM:SS |
 *    | message       | String     | Mandatory | Human readable outcome     |                     |
 *    | data          | Object     | Optional  | Null unless status is S    |                     |
 *    | data.baseUserId    | String | Mandatory | Id of the account         |                     |
 *    | data.username      | String | Mandatory | Display name              |                     |
 *    | data.email         | String | Mandatory | Email address             |                     |
 *    | data.contactNumber | String | Mandatory | Phone number              | digits, optional +  |
 *    | data.userType      | String | Mandatory | USER / FACILITY_OWNER / ADMIN |                |
 *    | data.accountStatus | String | Mandatory | ACTIVE / DEACTIVATED / SUSPENDED |             |
 *
 *  FUNCTION: getUserProfile
 *    Description : Public profile fields for a player, for the discovery and
 *                  social modules. Never returns an email or a phone number.
 *    Request adds nothing beyond baseUserId. Response data carries
 *    username, favoriteSports (an array), favoriteSport (the first of them, kept
 *    for callers written before the list existed), location, userType,
 *    memberSince and, only when
 *    the account has them, latitude and longitude rounded to 2 decimal places.
 *
 *  FUNCTION: accountExists
 *    Description : Yes/no check before another module writes a foreign key.
 *    Response data carries exists (boolean) and active (boolean).
 *
 * ---------------------------------------------------------------------------
 *  WHAT THIS ENDPOINT WILL NOT RETURN
 *  The password hash, the failed-attempt counter, the lockout time, reset
 *  tokens and the audit trail. None of them is anyone else's business, and a
 *  field that is never selected cannot be leaked by a caller that logs its
 *  responses. An owner's bank account number is not here either: the payment
 *  module asks for that through its own call, not through the profile.
 *
 *  Exact coordinates are not returned either. A player's home coordinates are
 *  personal data, so the discovery module gets them rounded to about a
 *  kilometre - enough to sort events by distance, not enough to find a house.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Model\AccountMapper;
use App\Model\FacilityOwner;
use App\Model\User;
use App\Service\Ifa;
use App\Service\ServiceLog;

$request   = Ifa::readRequest();
$requestId = is_string($request['requestId'] ?? null) ? $request['requestId'] : uuid();

$problem = Ifa::validateRequest($request);

if ($problem !== null) {
    Ifa::respond(Ifa::fail($requestId, $problem), 400);
}

$function = is_string($request['function'] ?? null) ? $request['function'] : '';

ServiceLog::start(
    $requestId,
    ServiceLog::INBOUND,
    is_string($request['sourceModule'] ?? null) ? $request['sourceModule'] : 'Unknown',
    'User Authentication & Profile Management',
    $function,
    (string) $request['timeStamp']
);

/** Rejects anything that is not the shape of one of our ids before it reaches a query. */
$readAccountId = static function (array $request): string {
    $baseUserId = $request['baseUserId'] ?? null;

    if (!is_string($baseUserId) || preg_match('/^[A-Za-z0-9-]{1,36}$/', $baseUserId) !== 1) {
        return '';
    }

    return $baseUserId;
};

try {
    $accounts = new AccountMapper();

    if ($function === 'getUserContactInfo') {
        $baseUserId = $readAccountId($request);

        if ($baseUserId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400);
            Ifa::respond(Ifa::fail($requestId, 'baseUserId is mandatory and must be a valid identifier.'), 400);
        }

        $account = $accounts->findAccount($baseUserId);

        // F, not E: the question was understood, the answer is no. And a
        // deactivated account reads the same as one that never existed, so this
        // endpoint cannot be walked to enumerate ids.
        if ($account === null || !$account->isActive()) {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 404);
            Ifa::respond(Ifa::fail($requestId, 'No active account with that id.'), 404);
        }

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, [
            'baseUserId'    => $account->getBaseUserId(),
            'username'      => $account->getUsername(),
            'email'         => $account->getEmail(),
            'contactNumber' => $account->getContactNumber(),
            'userType'      => $account->getUserType()->value,
            'accountStatus' => $account->getAccountStatus()->value,
        ], 'Contact details retrieved.'));
    }

    if ($function === 'getUserProfile') {
        $baseUserId = $readAccountId($request);

        if ($baseUserId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400);
            Ifa::respond(Ifa::fail($requestId, 'baseUserId is mandatory and must be a valid identifier.'), 400);
        }

        $account = $accounts->findAccount($baseUserId);

        if ($account === null || !$account->isActive()) {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 404);
            Ifa::respond(Ifa::fail($requestId, 'No active account with that id.'), 404);
        }

        $data = [
            'baseUserId'  => $account->getBaseUserId(),
            'username'    => $account->getUsername(),
            'userType'    => $account->getUserType()->value,
            'memberSince' => $account->getRegisterTime()?->format('Y-m-d'),
        ];

        if ($account instanceof User) {
            // A list now, not one value. favoriteSport is still sent, holding
            // the first of them, so a consumer written against the old shape
            // keeps working instead of silently reading null.
            $data['favoriteSports'] = $account->getFavoriteSports();
            $data['favoriteSport']  = $account->getFavoriteSport();
            $data['location']      = $account->getLocation();
            $data['profilePicURL'] = $account->getProfilePicURL();

            // Rounded to 2 decimal places, roughly a kilometre. Enough to sort
            // by distance, not enough to locate a person.
            if ($account->hasCoordinates()) {
                $data['approxLatitude']  = round((float) $account->getLatitude(), 2);
                $data['approxLongitude'] = round((float) $account->getLongitude(), 2);
            }
        }

        if ($account instanceof FacilityOwner) {
            $data['bankName'] = $account->getBankName();
            // Masked. The full number never crosses a module boundary here.
            $data['bankAccountNum'] = $account->getMaskedBankAccountNum();
        }

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, $data, 'Profile retrieved.'));
    }

    if ($function === 'accountExists') {
        $baseUserId = $readAccountId($request);

        if ($baseUserId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400);
            Ifa::respond(Ifa::fail($requestId, 'baseUserId is mandatory and must be a valid identifier.'), 400);
        }

        $account = $accounts->findAccount($baseUserId);

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, [
            'exists' => $account !== null,
            'active' => $account !== null && $account->isActive(),
        ], 'Account checked.'));
    }

    ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'Unknown function');
    Ifa::respond(Ifa::fail($requestId, 'Unknown function: ' . $function), 400);
} catch (Throwable $e) {
    // The caller gets a fixed sentence. The detail goes to our error log, where
    // a PDO message naming tables and columns is not something a caller reads.
    error_log('api/user: ' . $e->getMessage());

    ServiceLog::finish($requestId, Ifa::STATUS_ERROR, 500, 'Internal error');
    Ifa::respond(Ifa::error($requestId), 500);
}
