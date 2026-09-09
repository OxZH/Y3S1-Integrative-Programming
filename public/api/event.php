<?php
/*
Event web service (provider). Author: Goh Jian Yu
*/

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Domain\EventManagementFacade;
use App\Model\Event;
use App\NotFoundException;
use App\Service\Ifa;
use App\Service\ServiceLog;

$request   = Ifa::readRequest();
$requestId = is_string($request['requestId'] ?? null) ? $request['requestId'] : uuid();

$problem = Ifa::validateRequest($request);

if ($problem !== null) {
    Ifa::respond(Ifa::fail($requestId, $problem), 400);
}

$function = is_string($request['function'] ?? null) ? $request['function'] : 'unknown';
$viewerId = is_string($request['viewerId'] ?? null) && $request['viewerId'] !== '' ? $request['viewerId'] : null;

ServiceLog::start(
    $requestId,
    ServiceLog::INBOUND,
    is_string($request['sourceModule'] ?? null) ? $request['sourceModule'] : 'unknown',
    ServiceLog::thisModule(),
    $function,
    (string) $request['timeStamp']
);

function eventToArray(Event $event, EventManagementFacade $facade): array
{
    $facility  = $event->getLocation();
    $host      = $event->getHost();
    $confirmed = $facade->countParticipants((string) $event->getEventId());

    return [
        'eventId'               => $event->getEventId(),
        'name'                  => $event->getName(),
        'sport'                 => $event->getSport(),
        'eventDate'             => $event->getEventDate()->format('Y-m-d'),
        'startTime'             => $event->getStartTime(),
        'endTime'               => $event->getEndTime(),
        'durationHours'         => $event->getDurationHours(),
        'status'                => $event->getStatus()->value,
        'visibility'            => $event->getVisibility()->value,
        'skillLevel'            => $event->getSkillLevel()->value,
        'fitnessRequirement'    => $event->getFitnessRequirement()->value,
        'competitiveness'       => $event->getCompetitiveness()->value,
        'minParticipants'       => $event->getMinParticipants(),
        'maxParticipants'       => $event->getMaxParticipants(),
        'confirmedParticipants' => $confirmed,
        'spacesLeft'            => max(0, $event->getMaxParticipants() - $confirmed),
        'feePerParticipant'     => round($event->getFeePerParticipant(), 2),
        'host'                  => $host === null ? null : [
            'baseUserId' => $host->getBaseUserId(),
            'username'   => $host->getUsername(),
        ],
        'facility'              => $facility === null ? null : [
            'facilityId' => $facility->getFacilityId(),
            'name'       => $facility->getName(),
            'city'       => $facility->getCity(),
            'latitude'   => $facility->getLatitude(),
            'longitude'  => $facility->getLongitude(),
        ],
    ];
}

try {
    $facade = new EventManagementFacade();

    if ($function === 'getEventDetails') {
        $eventId = $request['eventId'] ?? null;

        if (!is_string($eventId) || $eventId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'eventId missing.');
            Ifa::respond(Ifa::fail($requestId, 'eventId is mandatory for getEventDetails.'), 400);
        }

        try {
            $event = $facade->viewEvent($eventId, $viewerId);
        } catch (NotFoundException $e) {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 404, 'Event not visible or missing.');
            Ifa::respond(Ifa::fail($requestId, 'No event exists with that id, or it is not visible to that viewer.'), 404);
        }

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);
        Ifa::respond(Ifa::success($requestId, eventToArray($event, $facade), 'Event retrieved.'));
    }

    if ($function === 'listUpcomingEvents') {
        $sport = is_string($request['sport'] ?? null) && $request['sport'] !== '' ? $request['sport'] : null;
        $limit = min(100, max(1, (int) ($request['limit'] ?? 50)));

        $events = array_map(
            function ($e) use ($facade) { return eventToArray($e, $facade); },
            $facade->listVisibleEvents($sport, $limit, $viewerId)
        );

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);

        Ifa::respond(Ifa::success(
            $requestId,
            ['count' => count($events), 'events' => $events],
            sprintf('%d events returned.', count($events))
        ));
    }

    if ($function === 'getEventsByFacility') {
        $facilityId = $request['facilityId'] ?? null;

        if (!is_string($facilityId) || $facilityId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'facilityId missing.');
            Ifa::respond(Ifa::fail($requestId, 'facilityId is mandatory for getEventsByFacility.'), 400);
        }

        $events = array_map(
            function ($e) use ($facade) { return eventToArray($e, $facade); },
            $facade->listEventsAtFacility($facilityId, true, $viewerId)
        );

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);

        Ifa::respond(Ifa::success(
            $requestId,
            ['count' => count($events), 'events' => $events],
            sprintf('%d events at that venue.', count($events))
        ));
    }

    ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'Unknown function: ' . $function);

    Ifa::respond(Ifa::fail(
        $requestId,
        'Unknown function. This endpoint offers getEventDetails, listUpcomingEvents and getEventsByFacility.'
    ), 400);
} catch (Throwable $e) {
    error_log('api/event: ' . $e->getMessage());

    ServiceLog::finish($requestId, Ifa::STATUS_ERROR, 500, $e->getMessage());

    Ifa::respond(Ifa::error($requestId), 500);
}
