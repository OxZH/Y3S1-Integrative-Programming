<?php
// Event and facility APIs consumed only by the payment module. Author: Khor Zhi Hong
//
// Every outbound call this module makes, in one place — the same IFA JSON POST
// style Jianyu uses in RemoteServices (ServiceClient + function name, not SOAP).
//
//   getEventDetails     Event & Facility     checkout quote, host, fee, times
//   getFacilityDetails  Event & Facility     hourly rate for the venue charge
//
// Payment never reads Event or Facility tables itself. Each call is logged with
// requestId / timeStamp and the S/F/E envelope is checked.

declare(strict_types=1);

namespace App\Service;

use DomainException;

final class PaymentRemoteServices
{
    private ServiceClient $client;

    public function __construct(?ServiceClient $client = null)
    {
        $this->client = $client ?? new ServiceClient();
    }

    /** @return array<string,mixed> */
    public function eventDetails(string $eventId, ?string $viewerId = null): array
    {
        $params = ['eventId' => $eventId];

        if ($viewerId !== null) {
            $params['viewerId'] = $viewerId;
        }

        $data = $this->client->call('event', 'getEventDetails', $params);

        if ($this->client->refused($data)) {
            throw new DomainException('The event does not exist or is not available to this account.');
        }

        return $data;
    }

    /** @return array<string,mixed> */
    public function facilityDetails(string $facilityId): array
    {
        $data = $this->client->call('facility', 'getFacilityDetails', ['facilityId' => $facilityId]);

        if ($this->client->refused($data)) {
            throw new DomainException('The venue is not available for booking.');
        }

        return $data;
    }
}
