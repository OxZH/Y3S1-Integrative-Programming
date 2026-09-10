<?php
/*
Stand-in for the teammate services this module consumes. Author: Goh Jian Yu
Delete this file once the real modules are up.
Functions: getUserContactInfo, getBookingStatus, getFacilityRatings, areFriends
*/

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;
use App\Service\Ifa;

$request   = Ifa::readRequest();
$requestId = is_string($request['requestId'] ?? null) ? $request['requestId'] : uuid();

$problem = Ifa::validateRequest($request);

if ($problem !== null) {
    Ifa::respond(Ifa::fail($requestId, $problem), 400);
}

$function = is_string($request['function'] ?? null) ? $request['function'] : '';
$pdo      = Database::getConnection();

try {
    if ($function === 'getUserContactInfo') {
        $baseUserId = $request['baseUserId'] ?? null;

        if (!is_string($baseUserId) || $baseUserId === '') {
            Ifa::respond(Ifa::fail($requestId, 'baseUserId is mandatory.'), 400);
        }

        $statement = $pdo->prepare(
            'SELECT `baseUserId`, `username`, `email`, `contactNumber`, `accountStatus`
               FROM `BaseUser` WHERE `baseUserId` = :id LIMIT 1'
        );
        $statement->execute([':id' => $baseUserId]);
        $row = $statement->fetch();

        if ($row === false || $row['accountStatus'] !== 'ACTIVE') {
            Ifa::respond(Ifa::fail($requestId, 'No active account with that id.'), 404);
        }

        Ifa::respond(Ifa::success($requestId, [
            'baseUserId'    => $row['baseUserId'],
            'username'      => $row['username'],
            'email'         => $row['email'],
            'contactNumber' => $row['contactNumber'],
        ], 'Contact details retrieved.'));
    }

    if ($function === 'getBookingStatus') {
        $eventId = $request['eventId'] ?? null;

        if (!is_string($eventId) || $eventId === '') {
            Ifa::respond(Ifa::fail($requestId, 'eventId is mandatory.'), 400);
        }

        $statement = $pdo->prepare(
            'SELECT b.`bookingId`, b.`bookingStatus`, b.`bookingAmount`, p.`paymentStatus`
               FROM `Booking` b
               LEFT JOIN `Payment` p ON p.`bookingId` = b.`bookingId`
              WHERE b.`eventId` = :eventId LIMIT 1'
        );
        $statement->execute([':eventId' => $eventId]);
        $row = $statement->fetch();

        // F, not E: no booking yet is the normal state of a new event.
        if ($row === false) {
            Ifa::respond(Ifa::fail($requestId, 'No booking exists for that event.'), 404);
        }

        Ifa::respond(Ifa::success($requestId, [
            'bookingId'     => $row['bookingId'],
            'bookingStatus' => $row['bookingStatus'],
            'paymentStatus' => $row['paymentStatus'] ?? 'PENDING',
            'amount'        => (float) $row['bookingAmount'],
        ], 'Booking status retrieved.'));
    }

    if ($function === 'getFacilityRatings') {
        $facilityIds = $request['facilityIds'] ?? [];

        if (!is_array($facilityIds)) {
            Ifa::respond(Ifa::fail($requestId, 'facilityIds must be an array.'), 400);
        }

        $facilityIds = array_slice(array_values(array_filter($facilityIds, 'is_string')), 0, 100);

        if ($facilityIds === []) {
            Ifa::respond(Ifa::success($requestId, ['ratings' => []], 'Nothing requested.'));
        }

        // Placeholders generated from the count, so the ids stay bound values.
        $placeholders = [];
        $params       = [];

        foreach ($facilityIds as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params[':id' . $i] = $id;
        }

        $statement = $pdo->prepare(
            'SELECT `facilityId`, AVG(`facilityRating`) AS average
               FROM `FacilityRating`
              WHERE `facilityId` IN (' . implode(', ', $placeholders) . ')
              GROUP BY `facilityId`'
        );
        $statement->execute($params);

        $ratings = [];

        foreach ($statement->fetchAll() as $row) {
            $ratings[(string) $row['facilityId']] = round((float) $row['average'], 1);
        }

        Ifa::respond(Ifa::success($requestId, ['ratings' => $ratings], count($ratings) . ' rated.'));
    }

    if ($function === 'areFriends') {
        $requesterId = $request['requesterId'] ?? null;
        $addresseeId = $request['addresseeId'] ?? null;

        if (!is_string($requesterId) || !is_string($addresseeId) || $requesterId === '' || $addresseeId === '') {
            Ifa::respond(Ifa::fail($requestId, 'requesterId and addresseeId are mandatory.'), 400);
        }

        // Friendship is mutual, so the pair is matched both ways. Only ACCEPTED counts.
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS total FROM `FriendConnection`
              WHERE `state` = 'ACCEPTED'
                AND ((`requesterId` = :a AND `addresseeId` = :b)
                  OR (`requesterId` = :c AND `addresseeId` = :d))"
        );
        $statement->execute([
            ':a' => $requesterId,
            ':b' => $addresseeId,
            ':c' => $addresseeId,
            ':d' => $requesterId,
        ]);

        $row = $statement->fetch();

        Ifa::respond(Ifa::success($requestId, [
            'areFriends' => ((int) ($row['total'] ?? 0)) > 0,
        ], 'Friendship checked.'));
    }

    // js part - added for Discovery & Event Matchmaking.
    if ($function === 'listFriends') {
        $baseUserId = $request['baseUserId'] ?? null;

        if (!is_string($baseUserId) || $baseUserId === '') {
            Ifa::respond(Ifa::fail($requestId, 'baseUserId is mandatory.'), 400);
        }

        // Friendship is undirected, so the accepted id can sit on either side of the pair.
        $statement = $pdo->prepare(
            "SELECT `requesterId`, `addresseeId` FROM `FriendConnection`
              WHERE `state` = 'ACCEPTED' AND (`requesterId` = :id OR `addresseeId` = :id2)"
        );
        $statement->execute([':id' => $baseUserId, ':id2' => $baseUserId]);

        $friendIds = [];

        foreach ($statement->fetchAll() as $row) {
            $friendIds[] = $row['requesterId'] === $baseUserId ? $row['addresseeId'] : $row['requesterId'];
        }

        Ifa::respond(Ifa::success($requestId, ['friendIds' => $friendIds], count($friendIds) . ' friend(s) found.'));
    }

    Ifa::respond(Ifa::fail($requestId, 'Unknown function: ' . $function), 400);
} catch (Throwable $e) {
    error_log('api/stub: ' . $e->getMessage());

    Ifa::respond(Ifa::error($requestId), 500);
}
