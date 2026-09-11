<?php
// The four web services this module consumes. Author: Goh Jian Yu

namespace App\Service;

use App\Model\Account;
use App\ServiceUnavailableException;
use DomainException;

// Every outbound call this module makes, in one place.
//
//   getUserContactInfo  User Authentication      owner details at onboarding
//   getBookingStatus    Venue Booking & Payment  gates event publication
//   getFacilityRatings  Social Networking        star ratings in search
//   areFriends          Social Networking        friends-only visibility
//
// Each is bidirectional with a module that also consumes one of ours.
final class RemoteServices
{
    private ServiceClient $client;

    public function __construct(?ServiceClient $client = null)
    {
        $this->client = $client ?? new ServiceClient();
    }

    // Contact details for a venue owner, falling back to the copy we already
    // hold. These are convenience data, not a security decision, so an owner
    // filling in a form is not blocked because another module is restarting.
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

    public function bookingStatus(string $eventId): BookingStatus
    {
        $data = $this->client->call('booking', 'getBookingStatus', ['eventId' => $eventId]);

        // No booking yet is the normal state of a new event, not a breakage.
        return $this->client->refused($data) ? BookingStatus::missing() : BookingStatus::fromArray($data);
    }

    public function cancelEventPayments(string $eventId, string $reason): void
    {
        $data = $this->client->call(
            'booking',
            'cancelEventPayments',
            ['eventId' => $eventId, 'reason' => $reason],
            $this->paymentHeaders()
        );

        if ($this->client->refused($data)) {
            throw new DomainException('Participant payments could not be cancelled.');
        }
    }

    /** @return array<string,mixed> */
    public function settleEventPayout(string $eventId): array
    {
        $data = $this->client->call(
            'booking',
            'settleEventPayout',
            ['eventId' => $eventId],
            $this->paymentHeaders()
        );

        if ($this->client->refused($data)) {
            throw new DomainException('The participant payout is not ready.');
        }

        return $data;
    }

    // One call carrying every id on the page, not one call per row.
    // Ratings are decoration, so a failure here degrades to an empty column
    // rather than breaking the search page.
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

    // Unlike ratings there is no swallowing wrapper here: this answer is a
    // security decision, so the caller must handle the failure and fail closed.
    public function areFriends(string $userA, string $userB): bool
    {
        $data = $this->client->call('friend', 'areFriends', [
            'requesterId' => $userA,
            'addresseeId' => $userB,
        ]);

        if ($this->client->refused($data)) {
            return false;
        }

        return ($data['areFriends'] ?? false) === true;
    }

    /** @return string[] */
    private function paymentHeaders(): array
    {
        $key = (string) config('services.booking.key', '');

        return $key === '' ? [] : ['X-Service-Key: ' . $key];
    }
}
