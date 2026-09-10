<?php
/**
 * Discovery & Event Matchmaking web service (exposed). Author: Ng Jing Siang
 *
 * INTERFACE AGREEMENT
 *
 * Protocol       RESTful, JSON over HTTP POST
 * Description    Returns a user's past participation, and a rule-based list of
 *                events recommended to them.
 * Source Module  Discovery & Event Matchmaking
 * Target Module  User Authentication & Profile Management (Recently Participated
 *                History tab), Social Networking & Review System
 * URL            http://localhost:8000/api/discovery.php
 * Function Name  getParticipationHistory, getRecommendedEvents
 *
 * Request
 * Field       Type     M/O        Description               Format
 * requestId   String   Mandatory  Unique id for the call    36 chars max
 * timeStamp   String   Mandatory  When it was sent          YYYY-MM-DD HH:MM:SS
 * function    String   Mandatory  Operation wanted          getParticipationHistory,
 *                                                           getRecommendedEvents
 * userId      String   Mandatory  Whose history/feed wanted UUID
 * viewerId    String   Optional   Who is asking             UUID, getParticipationHistory only
 * limit       Integer  Optional   Max rows                  getParticipationHistory 1-50,
 *                                                           default 10; getRecommendedEvents
 *                                                           1-100, default 10
 *
 * Response
 * Field       Type     M/O        Description               Format
 * status      String   Mandatory  Result of the request     S: Success
 *                                                           F: Fail
 *                                                           E: Error
 * requestId   String   Mandatory  Echo of the request id
 * timeStamp   String   Mandatory  When generated            YYYY-MM-DD HH:MM:SS
 * message     String   Mandatory  Outcome in words
 * data        Object   Optional   Null on F and E
 *                                 getParticipationHistory -> count, registrations[]
 *                                 getRecommendedEvents    -> count, events[]
 *
 * registration: eventId, eventName, sport, eventDate, status, registerTime,
 * available. The registration rows are this module's own; the event behind each
 * one is described by Event & Facility Management, asked with viewerId attached.
 * available is false when that module will not show the event to this viewer, or
 * is unreachable - the row is still returned, because the person did join it,
 * but eventName, sport and eventDate come back null.
 *
 * event: eventId, name, sport, eventDate, startTime, spacesLeft,
 * recommendationScore, recommendationReason.
 *
 * This module never exposes a member's own coordinates, on this endpoint or any
 * other - see the module's Software Security section (Access to Sensitive Data /
 * location privacy).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Domain\Discovery\EventFeedItem;
use App\Domain\DiscoveryFacade;
use App\Service\Ifa;
use App\Service\ServiceLog;

$request   = Ifa::readRequest();
$requestId = is_string($request['requestId'] ?? null) ? $request['requestId'] : uuid();

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
function feedItemToArray(EventFeedItem $item): array
{
    return [
        'eventId'               => $item->eventId,
        'name'                  => $item->name,
        'sport'                 => $item->sport,
        'eventDate'             => $item->eventDate,
        'startTime'             => $item->startTime,
        'spacesLeft'            => $item->spacesLeft,
        'recommendationScore'   => $item->recommendationScore,
        'recommendationReason'  => $item->recommendationReason,
    ];
}

try {
    $facade = new DiscoveryFacade();

    if ($function === 'getParticipationHistory') {
        $userId = $request['userId'] ?? null;

        if (!is_string($userId) || $userId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'userId missing.');
            Ifa::respond(Ifa::fail($requestId, 'userId is mandatory for getParticipationHistory.'), 400);
        }

        // Optional: without it the rows still come back, but nothing can be said
        // about events whose visibility depends on who is asking.
        $viewerId = is_string($request['viewerId'] ?? null) && $request['viewerId'] !== ''
            ? $request['viewerId']
            : null;

        $registrations = $facade->participationHistoryFor(
            $userId,
            $viewerId,
            min(50, max(1, (int) ($request['limit'] ?? 10)))
        );

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);

        Ifa::respond(Ifa::success(
            $requestId,
            ['count' => count($registrations), 'registrations' => $registrations],
            sprintf('%d past registration(s) returned.', count($registrations))
        ));
    }

    if ($function === 'getRecommendedEvents') {
        $userId = $request['userId'] ?? null;

        if (!is_string($userId) || $userId === '') {
            ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'userId missing.');
            Ifa::respond(Ifa::fail($requestId, 'userId is mandatory for getRecommendedEvents.'), 400);
        }

        $limit  = min(100, max(1, (int) ($request['limit'] ?? 10)));
        $events = array_map('feedItemToArray', $facade->recommendedEvents($userId, $limit));

        ServiceLog::finish($requestId, Ifa::STATUS_SUCCESS, 200);

        Ifa::respond(Ifa::success(
            $requestId,
            ['count' => count($events), 'events' => $events],
            sprintf('%d recommended event(s) returned.', count($events))
        ));
    }

    ServiceLog::finish($requestId, Ifa::STATUS_FAIL, 400, 'Unknown function: ' . $function);

    Ifa::respond(Ifa::fail(
        $requestId,
        'Unknown function. This endpoint offers getParticipationHistory and getRecommendedEvents.'
    ), 400);
} catch (Throwable $e) {
    error_log('api/discovery: ' . $e->getMessage());

    ServiceLog::finish($requestId, Ifa::STATUS_ERROR, 500, $e->getMessage());

    Ifa::respond(Ifa::error($requestId), 500);
}
