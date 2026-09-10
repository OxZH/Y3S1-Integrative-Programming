<?php
// The web services this module consumes. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Service;

use App\ServiceUnavailableException;

/**
 * Every outbound call this module makes, in one place - mirrors
 * App\Service\RemoteServices in the neighbouring module.
 *
 *   listUpcomingEvents  Event & Facility Management  the browse/map/recommend feed
 *   getEventDetails     Event & Facility Management  re-authorisation at the point of join
 *   getUserProfile      User Authentication          favourite sport + home location for recommendations
 *   listFriends         Social Networking            whose registrations count as "a friend is going"
 *   facilityRatings     Social Networking            star rating shown on the browse list, sortable
 *
 * getEventDetails is the important one: this module never re-implements Event &
 * Facility Management's Public/Friends-Only rule. It asks, fresh, every time a
 * join is attempted, and takes S or F at face value.
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
     * Refused (F) reads as "not visible to this viewer right now" - the same
     * event.php the browse page uses, called again at the moment of the write
     * instead of trusting whatever the page showed a moment ago.
     *
     * @return array<string,mixed>|null null when the event does not exist, is not
     *         published, or is friends-only and this viewer is not a friend
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
     * User Authentication & Profile Management rounds a player's coordinates to
     * about a kilometre before this ever leaves their module (approxLatitude /
     * approxLongitude) - the same "never hand out exact coordinates" rule this
     * module's own Software Security section relies on for the map. Renamed to
     * plain latitude/longitude here only so the rest of this module does not
     * need to know or care whose API supplied the number.
     *
     * @return array{favoriteSport:?string,latitude:?float,longitude:?float}|null
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
            'favoriteSport' => isset($data['favoriteSport']) ? (string) $data['favoriteSport'] : null,
            'latitude'      => isset($data['approxLatitude']) ? (float) $data['approxLatitude'] : null,
            'longitude'     => isset($data['approxLongitude']) ? (float) $data['approxLongitude'] : null,
        ];
    }

    /**
     * The "who is going" signal is decoration, so a failure here degrades to
     * "no friend signal shown" rather than breaking the feed - same philosophy
     * as facilityRatings() below.
     *
     * @return string[]
     */
    public function friendIds(string $baseUserId): array
    {
        try {
            $data = $this->client->call('friend', 'listFriends', ['baseUserId' => $baseUserId]);
        } catch (ServiceUnavailableException $e) {
            error_log('Friend service unavailable, hiding the friend signal: ' . $e->getMessage());

            return [];
        }

        if ($this->client->refused($data) || !is_array($data['friendIds'] ?? null)) {
            return [];
        }

        return array_values(array_filter($data['friendIds'], 'is_string'));
    }

    /**
     * One call carrying every facility id on the page, not one call per row -
     * same batching reason as RemoteServices::facilityRatings() in the
     * neighbouring module. Ratings are decoration, so a failure here degrades
     * to an empty column rather than breaking the feed.
     *
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
