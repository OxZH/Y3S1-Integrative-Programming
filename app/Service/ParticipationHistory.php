<?php
// Consumes the Discovery service to build a user's activity history. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Service;

use App\ServiceUnavailableException;

/**
 * Module 2's outbound web service call for the "Recently Participated History"
 * tab.
 *
 * Module 2 owns none of this. Which events somebody joined lives in
 * EventRegistration, which belongs to Discovery & Event Matchmaking, and what
 * each event actually is belongs to Event & Facility Management. So it asks
 * rather than reads: one getParticipationHistory call, through the agreed IFA
 * envelope, and the answer comes back already joined up.
 *
 * This used to read EventRegistration directly with SQL and then make one
 * getEventDetails call per row. That was a module boundary crossed for the
 * registration rows and N+1 HTTP calls for the rest. Discovery already does
 * both halves in one call, including the visibility check, so there is nothing
 * left here to do by hand.
 *
 * viewerId is the person looking at the page, not the person the page is about.
 * That distinction matters on somebody else's profile: the history shown there
 * has to be what the visitor is allowed to see, and Discovery decides that with
 * the viewerId it is given.
 *
 * The history is a display, not a security decision, so a module that is down
 * degrades to an empty list rather than an error page.
 */
final class ParticipationHistory
{
    private ServiceClient $client;

    public function __construct(?ServiceClient $client = null)
    {
        $this->client = $client ?? new ServiceClient();
    }

    /**
     * @return array<int,array{eventId:string,status:string,registeredAt:string,
     *         name:string,sport:string,eventDate:string,available:bool}>
     */
    public function forUser(string $baseUserId, ?string $viewerId = null, int $limit = 10): array
    {
        try {
            $data = $this->client->call('discovery', 'getParticipationHistory', [
                'userId'   => $baseUserId,
                'viewerId' => $viewerId,
                'limit'    => max(1, min(50, $limit)),
            ]);
        } catch (ServiceUnavailableException $e) {
            error_log('Participation history: ' . $e->getMessage());

            return [];
        }

        // A refusal is a real answer rather than a fault, and there is nothing
        // partial to show from one call, so the tab is simply empty.
        if ($this->client->refused($data) || !is_array($data['registrations'] ?? null)) {
            return [];
        }

        $history = [];

        foreach ($data['registrations'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $history[] = $this->row($row);
        }

        return $history;
    }

    /**
     * One row of the response, in the shape the view already expects.
     *
     * @param array<string,mixed> $row
     * @return array{eventId:string,status:string,registeredAt:string,
     *         name:string,sport:string,eventDate:string,available:bool}
     */
    private function row(array $row): array
    {
        $available = ($row['available'] ?? false) === true;

        $common = [
            'eventId'      => $this->text($row, 'eventId', ''),
            'status'       => $this->text($row, 'status', '-'),
            'registeredAt' => $this->text($row, 'registerTime', '-'),
        ];

        // Discovery marks a row unavailable when the viewer may no longer see
        // that event, and sends no details with it. The row still appears,
        // because the person really did join it.
        if (!$available) {
            return $common + [
                'name'      => 'Event details unavailable',
                'sport'     => '-',
                'eventDate' => '-',
                'available' => false,
            ];
        }

        return $common + [
            'name'      => $this->text($row, 'eventName', 'Untitled event'),
            'sport'     => $this->text($row, 'sport', '-'),
            'eventDate' => $this->text($row, 'eventDate', '-'),
            'available' => true,
        ];
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, string $key, string $fallback): string
    {
        return is_scalar($row[$key] ?? null) ? (string) $row[$key] : $fallback;
    }
}
