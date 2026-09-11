<?php
// The web services this module consumes. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Service;

use App\ServiceUnavailableException;

/**
 * All web service calls this module makes to other modules.
 *
 *   listUpcomingEvents  Event module    events for browse / map / recommend
 *   getEventDetails     Event module    one event, checked against the viewer
 *   getUserProfile      User module     favourite sports + home location
 *   getFacilityRatings  Social module   star ratings for the cards
 */
final class DiscoveryRemoteServices
{
    private ServiceClient $client;

    public function __construct(?ServiceClient $client = null)
    {
        $this->client = $client ?? new ServiceClient();
    }

    /** @return array<int,array<string,mixed>> */
    public function listUpcomingEvents(?string $sport, ?string $viewerId, int $limit = 50): array
    {
        $params = ['limit' => $limit];

        if ($sport !== null) {
            $params['sport'] = $sport;
        }

        if ($viewerId !== null) {
            $params['viewerId'] = $viewerId;
        }

        $data = $this->client->call('event', 'listUpcomingEvents', $params);

        if ($this->client->refused($data) || !is_array($data['events'] ?? null)) {
            return [];
        }

        return $data['events'];
    }

    /**
     * The Event module decides if this viewer can see the event. A refusal (F) comes back as null.
     * @return array<string,mixed>|null
     */
    public function getEventDetails(string $eventId, ?string $viewerId): ?array
    {
        $params = ['eventId' => $eventId];

        if ($viewerId !== null) {
            $params['viewerId'] = $viewerId;
        }

        $data = $this->client->call('event', 'getEventDetails', $params);

        return $this->client->refused($data) ? null : $data;
    }

    /**
     * The User module only gives rounded coordinates (approxLatitude/approxLongitude).
     * Renamed to latitude/longitude here for the rest of the module.
     * @return array{favoriteSports:string[],latitude:?float,longitude:?float}|null
     */
    public function userProfile(string $baseUserId): ?array
    {
        try {
            $data = $this->client->call('profile', 'getUserProfile', ['baseUserId' => $baseUserId]);
        } catch (ServiceUnavailableException $e) {
            error_log('Profile service unavailable, recommendations degrade to distance only: ' . $e->getMessage());

            return null;
        }

        if ($this->client->refused($data)) {
            return null;
        }

        return [
            // favoriteSports is the new list, favoriteSport is the old single value
            'favoriteSports' => is_array($data['favoriteSports'] ?? null)
                ? array_values(array_filter($data['favoriteSports'], 'is_string'))
                : (isset($data['favoriteSport']) ? [(string) $data['favoriteSport']] : []),
            'latitude'      => isset($data['approxLatitude']) ? (float) $data['approxLatitude'] : null,
            'longitude'     => isset($data['approxLongitude']) ? (float) $data['approxLongitude'] : null,
        ];
    }

    /**
     * Ratings for all facilities on the page in one call. If the service is down
     * we just show no ratings.
     * @param string[] $facilityIds
     * @return array<string,float> facilityId => average rating
     */
    public function facilityRatings(array $facilityIds): array
    {
        $facilityIds = array_values(array_unique(array_filter($facilityIds, 'is_string')));

        if ($facilityIds === []) {
            return [];
        }

        try {
            $data = $this->client->call('rating', 'getFacilityRatings', ['facilityIds' => $facilityIds]);
        } catch (ServiceUnavailableException $e) {
            error_log('Rating service unavailable, hiding ratings: ' . $e->getMessage());

            return [];
        }

        if ($this->client->refused($data) || !is_array($data['ratings'] ?? null)) {
            return [];
        }

        $ratings = [];

        foreach ($data['ratings'] as $facilityId => $average) {
            if (is_string($facilityId) && is_numeric($average)) {
                $ratings[$facilityId] = round((float) $average, 1);
            }
        }

        return $ratings;
    }
}
