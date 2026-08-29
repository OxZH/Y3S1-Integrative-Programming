<?php
/**
 * Facility web service (exposed). Author: Goh Jian Yu
 *
 * INTERFACE AGREEMENT
 *
 * Protocol       RESTful, JSON over HTTP POST
 * Description    Returns bookable venue information: one venue's details, or a
 *                filtered, distance-sorted list.
 * Source Module  Event & Facility Management
 * Target Module  Venue Booking & Payment, Discovery & Event Matchmaking,
 *                Social Networking & Review System
 * URL            http://localhost:8000/api/facility.php
 * Function Name  getFacilityDetails, searchFacilities
 *
 * Request
 * Field       Type     M/O        Description             Format
 * requestId   String   Mandatory  Unique id for the call  36 chars max
 * timeStamp   String   Mandatory  When it was sent        YYYY-MM-DD HH:MM:SS
 * function    String   Mandatory  Operation wanted        getFacilityDetails,
 *                                                         searchFacilities
 * facilityId  String   Mandatory  Venue wanted            UUID, getFacilityDetails only
 * keyword     String   Optional   Name or street match    searchFacilities only
 * city        String   Optional   Filter by city          searchFacilities only
 * type        String   Optional   Filter by venue type    searchFacilities only
 * maxFee      Decimal  Optional   Highest hourly fee      searchFacilities only
 * lat, lng    Decimal  Optional   Origin for distance     searchFacilities only
 * radius      Decimal  Optional   Limit in km             searchFacilities only
 * limit       Integer  Optional   Max rows                1-100, default 50
 *
 * Response
 * Field       Type     M/O        Description             Format
 * status      String   Mandatory  Result of the request   S: Success
 *                                                         F: Fail
 *                                                         E: Error
 * requestId   String   Mandatory  Echo of the request id
 * timeStamp   String   Mandatory  When generated          YYYY-MM-DD HH:MM:SS
 * message     String   Mandatory  Outcome in words
 * data        Object   Optional   Null on F and E
 *                                 getFacilityDetails -> facility
 *                                 searchFacilities   -> count, facilities[]
 *
 * facility: facilityId, name, type, addressLine, city, state, fullAddress,
 * bookingFee, operationalHrsStart, operationalHrsEnd, latitude, longitude,
 * status, imageUrl, owner{baseUserId, username, contactNumber}, distanceKm
 * (search only, when lat and lng are given).
 *
 * Only ACTIVE venues are exposed. The owner's bank details sit on the same
 * tables and are deliberately absent.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Domain\EventManagementFacade;
use App\Domain\SearchCriteria;
use App\Model\Facility;
use App\NotFoundException;
use App\Service\Ifa;
use App\Service\ServiceLog;

$request   = Ifa::readRequest();
$requestId = is_string($request['requestId'] ?? null) ? $request['requestId'] : uuid();

// Checked first: without these the call cannot be traced in either module's log.
$problem = Ifa::validateRequest($request);

if ($problem !== null) {
    Ifa::respond(Ifa::fail($requestId, $problem), 400);
}

$function = is_string($request['function'] ?? null) ? $request['function'] : 'unknown';

ServiceLog::start(
    $requestId,
    ServiceLog::INBOUND,
    is_string($request['sourceModule'] ?? null) ? $request['sourceModule'] : 'unknown',
    ServiceLog::thisModule(),
    $function,
    (string) $request['timeStamp']
);

/** @return array<string,mixed> */
function facilityToArray(Facility $facility, ?float $originLat = null, ?float $originLng = null): array
{
    $owner = $facility->getOwner();

    $payload = [
        'facilityId'          => $facility->getFacilityId(),
        'name'                => $facility->getName(),
        'type'                => $facility->getType(),
        'addressLine'         => $facility->getAddressLine(),
        'city'                => $facility->getCity(),
        'state'               => $facility->getState(),
        'fullAddress'         => $facility->getFullAddress(),
        'bookingFee'          => round($facility->getBookingFee(), 2),
        'operationalHrsStart' => $facility->getOperationalHrsStart(),
        'operationalHrsEnd'   => $facility->getOperationalHrsEnd(),
        'latitude'            => $facility->getLatitude(),
        'longitude'           => $facility->getLongitude(),
        'status'              => $facility->getStatus()->value,
        'imageUrl'            => $facility->getImageUrl(),
        'owner'               => $owner === null ? null : [
            'baseUserId'    => $owner->getBaseUserId(),
            'username'      => $owner->getUsername(),
            'contactNumber' => $owner->getContactNumber(),
        ],
    ];

    if ($originLat !== null && $originLng !== null) {
        $payload['distanceKm'] = round($facility->distanceFromKm($originLat, $originLng), 2);
    }

    return $payload;
}

try {
    $facade = new EventManagementFacade();

    if ($function === 'getFacilityDetails') {
        $facilityId = $request['facilityId'] ?? null;

        if (!is_string($facilityId) || $facilityId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'facilityId missing.');
            Ifa::respond(Ifa::fail($requestId, 'facilityId is mandatory for getFacilityDetails.'), 400);
        }

        try {
            $facility = $facade->getFacility($facilityId);
        } catch (NotFoundException $e) {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 404, 'Facility not found.');
            Ifa::respond(Ifa::fail($requestId, 'No facility exists with that id.'), 404);
        }

        // A pending or delisted venue answers the same as one that does not exist.
        if (!$facility->isBookable()) {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 404, 'Facility not bookable.');
            Ifa::respond(Ifa::fail($requestId, 'No facility exists with that id.'), 404);
        }

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, facilityToArray($facility), 'Facility retrieved.'));
    }

    if ($function === 'searchFacilities') {
        $criteria = SearchCriteria::fromArray([
            'q'       => $request['keyword'] ?? null,
            'city'    => $request['city'] ?? null,
            'type'    => $request['type'] ?? null,
            'max_fee' => $request['maxFee'] ?? null,
            'lat'     => $request['lat'] ?? null,
            'lng'     => $request['lng'] ?? null,
            'radius'  => $request['radius'] ?? null,
            'sort'    => $request['sort'] ?? 'name',
            'limit'   => $request['limit'] ?? 50,
        ]);

        $facilities = array_map(
            static fn (Facility $f): array => facilityToArray($f, $criteria->latitude, $criteria->longitude),
            $facade->searchFacilities($criteria)
        );

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);

        Ifa::respond(Ifa::success(
            $requestId,
            ['count' => count($facilities), 'facilities' => $facilities],
            sprintf('%d facilities matched.', count($facilities))
        ));
    }

    ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'Unknown function: ' . $function);

    Ifa::respond(Ifa::fail(
        $requestId,
        'Unknown function. This endpoint offers getFacilityDetails and searchFacilities.'
    ), 400);
} catch (Throwable $e) {
    // The caller learns that it broke, never how.
    error_log('api/facility: ' . $e->getMessage());

    ServiceLog::finish($requestId, Ifa::STATUS_ERROR, 500, $e->getMessage());

    Ifa::respond(Ifa::error($requestId), 500);
}
