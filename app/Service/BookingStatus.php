<?php
// Booking state reported by the Venue Booking & Payment module. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Service;

/**
 * This module never reads the Booking, Payment or Refund tables. Their contents
 * arrive only through this object.
 */
final class BookingStatus
{
    public function __construct(
        public readonly bool $exists,
        public readonly ?string $bookingId = null,
        public readonly ?string $bookingStatus = null,
        public readonly ?string $paymentStatus = null,
        public readonly ?float $amount = null
    ) {
    }

    public static function missing(): self
    {
        return new self(false);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['bookingId'])) {
            return self::missing();
        }

        return new self(
            true,
            (string) $data['bookingId'],
            isset($data['bookingStatus']) ? (string) $data['bookingStatus'] : null,
            isset($data['paymentStatus']) ? (string) $data['paymentStatus'] : null,
            isset($data['amount']) ? (float) $data['amount'] : null
        );
    }

    // Written as an allow-list of the two acceptable values rather than "not
    // cancelled": if that module adds a state later, an unrecognised value must
    // read as not ready, never as fine.
    public function isSettled(): bool
    {
        return $this->exists
            && $this->bookingStatus === 'CONFIRMED'
            && $this->paymentStatus === 'PAID';
    }

    public function describe(): string
    {
        if (!$this->exists) {
            return 'No booking has been made for this event yet.';
        }

        return sprintf(
            'Booking is %s and payment is %s.',
            strtolower((string) $this->bookingStatus),
            strtolower((string) ($this->paymentStatus ?? 'not started'))
        );
    }
}
