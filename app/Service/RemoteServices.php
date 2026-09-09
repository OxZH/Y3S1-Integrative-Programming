<?php
// The four web services this module consumes. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Service;

use App\Model\Account;
use App\Model\FriendConnectionMapper;
use App\ServiceUnavailableException;

/**
 * Every outbound call this module makes, in one place.
 *
 *   getUserContactInfo  User Authentication      owner details at onboarding
 *   getBookingStatus    Venue Booking & Payment  gates event publication
 *   getFacilityRatings  Social Networking        star ratings in search
 *   areFriends          Social Networking        friends-only visibility
 *
 * Each is bidirectional with a module that also consumes one of ours.
 */
final class RemoteServices
{
    private ServiceClient $client;
    private ?FriendConnectionMapper $friendConnections;

    public function __construct(
        ?ServiceClient $client = null,
        ?FriendConnectionMapper $friendConnections = null
    ) {
        $this->client            = $client ?? new ServiceClient();
        $this->friendConnections = $friendConnections;
    }

    /**
     * Contact details for a venue owner, falling back to the copy we already
     * hold. These are convenience data, not a security decision, so an owner
     * filling in a form is not blocked because another module is restarting.
     *
     * @return array{username:string,email:string,contactNumber:string}
     */
    public function contactFor(Account $owner): array
    {
        $local = [
            'username'      => $owner->getUsername(),
            'email'         => $owner->getEmail(),
            'contactNumber' => $owner->getContactNumber(),
        ];

        try {
            $data = $this->client->call('profile', 'getUserContactInfo', [
                'baseUserId' => $owner->getBaseUserId(),
            ]);

            if ($this->client->refused($data)) {
                return $local;
            }

            foreach (['username', 'email', 'contactNumber'] as $field) {
                if (isset($data[$field]) && is_scalar($data[$field])) {
                    $local[$field] = (string) $data[$field];
                }
            }
        } catch (ServiceUnavailableException $e) {
            error_log('Profile service unavailable, using local copy: ' . $e->getMessage());
        }

        return $local;
    }

    /** @throws ServiceUnavailableException */
    public function bookingStatus(string $eventId): BookingStatus
    {
        $data = $this->client->call('booking', 'getBookingStatus', ['eventId' => $eventId]);

        // No booking yet is the normal state of a new event, not a breakage.
        return $this->client->refused($data) ? BookingStatus::missing() : BookingStatus::fromArray($data);
    }

    /**
     * One call carrying every id on the page, not one call per row.
     * Ratings are decoration, so a failure here degrades to an empty column
     * rather than breaking the search page.
     *
     * @param string[] $facilityIds
     * @return array<string,float>
     */
    public function facilityRatings(array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }

        try {
            $data = $this->client->call('rating', 'getFacilityRatings', [
                'facilityIds' => array_values($facilityIds),
            ]);
        } catch (ServiceUnavailableException $e) {
            error_log('Rating service unavailable: ' . $e->getMessage());

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

    public function areFriends(string $userA, string $userB): bool
    {
        $this->friendConnections ??= new FriendConnectionMapper();

        return $this->friendConnections->areFriends($userA, $userB);
    }
}
