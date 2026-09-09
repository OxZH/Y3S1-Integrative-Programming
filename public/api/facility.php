<?php
/*
  Facility web service (provider). Author: Goh Jian Yu
*/

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
            function ($f) use ($criteria) { return facilityToArray($f, $criteria->latitude, $criteria->longitude); },
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
