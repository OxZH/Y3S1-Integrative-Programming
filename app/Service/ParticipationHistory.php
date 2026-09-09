<?php
// Consumes the Event & Facility service to build a user's activity history. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\ServiceUnavailableException;

/**
 * Module 2's outbound web service call.
 *
 * The "Recently Participated History" tab needs an event's name, sport and date.
 * Module 2 does not own any of that, and must not read module 1's tables to get
 * it, so it asks: one getEventDetails call per event the user registered for,
 * through the agreed IFA envelope.
 *
 * What is read locally is only the join rows - which events this user signed up
 * for, and when - because that is the link between an account and an event, and
 * the id is all that is needed to ask the question.
 *
 * The history is a display, not a security decision, so a module that is down
 * degrades to a row marked unavailable rather than an error page.
 */
final class ParticipationHistory
{
    private ServiceClient $client;

    public function __construct(?ServiceClient $client = null)
    {
        $this->client = $client ?? new ServiceClient();
    }

    /**
     * @return array<int,array{eventId:string,status:string,registeredAt:string,name:string,sport:string,eventDate:string,available:bool}>
     */
    public function forUser(string $baseUserId, int $limit = 10): array
    {
        $registrations = $this->registrationsFor($baseUserId, $limit);
        $history       = [];

        foreach ($registrations as $registration) {
            $history[] = $this->describe($registration, $baseUserId);
        }

        return $history;
    }

    /**
     * The local half: which events, and how the user's registration stands.
     *
     * @return array<int,array{eventId:string,status:string,registeredAt:string}>
     */
    private function registrationsFor(string $baseUserId, int $limit): array
    {
        // LIMIT cannot be a bound parameter, so the value is forced into range.
        $safeLimit = max(1, min(50, $limit));

        $statement = Database::getConnection()->prepare(
            'SELECT `eventId`, `status`, `registerTime`
               FROM `EventRegistration`
              WHERE `userId` = :id
              ORDER BY `registerTime` DESC
              LIMIT ' . $safeLimit
        );
        $statement->execute([':id' => $baseUserId]);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'eventId'      => (string) $row['eventId'],
                'status'       => (string) $row['status'],
                'registeredAt' => (string) $row['registerTime'],
            ];
        }

        return $rows;
    }

    /**
     * The remote half: ask module 1 what the event actually is.
     *
     * @param array{eventId:string,status:string,registeredAt:string} $registration
     * @return array{eventId:string,status:string,registeredAt:string,name:string,sport:string,eventDate:string,available:bool}
     */
    private function describe(array $registration, string $viewerId): array
    {
        $unavailable = $registration + [
            'name'      => 'Event details unavailable',
            'sport'     => '-',
            'eventDate' => '-',
            'available' => false,
        ];

        try {
            // viewerId is part of the agreed request: it asks module 1 to decide
            // visibility for this particular person, now. A friends-only event
            // this user has since lost access to comes back refused, and the row
            // reads "unavailable" - the answer is not cached or assumed here.
            $data = $this->client->call('event', 'getEventDetails', [
                'eventId'  => $registration['eventId'],
                'viewerId' => $viewerId,
            ]);
        } catch (ServiceUnavailableException $e) {
            error_log('Participation history: ' . $e->getMessage());

            return $unavailable;
        }

        // A refusal is a real answer - the event may have been deleted, or this
        // user may no longer be allowed to see it. Either way, not an error.
        if ($this->client->refused($data)) {
            return $unavailable;
        }

        return $registration + [
            'name'      => is_scalar($data['name'] ?? null) ? (string) $data['name'] : 'Untitled event',
            'sport'     => is_scalar($data['sport'] ?? null) ? (string) $data['sport'] : '-',
            'eventDate' => is_scalar($data['eventDate'] ?? null) ? (string) $data['eventDate'] : '-',
            'available' => true,
        ];
    }
}
