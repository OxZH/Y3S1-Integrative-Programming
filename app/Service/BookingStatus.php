<?php
// Booking state reported by the Venue Booking & Payment module. Author: Goh Jian Yu

namespace App\Service;

// This module never reads the Booking, Payment or Refund tables. Whatever it
// knows about a booking arrives only through this object.
class BookingStatus
{
    public $exists;
    public $bookingId;
    public $bookingStatus;
    public $paymentStatus;
    public $amount;

    public function __construct(
        $exists,
        $bookingId = null,
        $bookingStatus = null,
        $paymentStatus = null,
        $amount = null
    ) {
        $this->exists = $exists;
        $this->bookingId = $bookingId;
        $this->bookingStatus = $bookingStatus;
        $this->paymentStatus = $paymentStatus;
        $this->amount = $amount;
    }

    public static function missing()
    {
        return new BookingStatus(false);
    }

    public static function fromArray(array $data)
    {
        if (!isset($data['bookingId'])) {
            return BookingStatus::missing();
        }

        return new BookingStatus(
            true,
            $data['bookingId'],
            isset($data['bookingStatus']) ? $data['bookingStatus'] : null,
            isset($data['paymentStatus']) ? $data['paymentStatus'] : null,
            isset($data['amount']) ? (float) $data['amount'] : null
        );
    }

    // Written as a list of the two values we accept, rather than as "not
    // cancelled". If the other module adds a new state later, a value we do not
    // recognise has to read as not ready, never as fine.
    public function isSettled()
    {
        return $this->exists
            && $this->bookingStatus === 'CONFIRMED'
            && $this->paymentStatus === 'PAID';
    }

    public function describe()
    {
        if (!$this->exists) {
            return 'No booking has been made for this event yet.';
        }

        $payment = $this->paymentStatus === null ? 'not started' : $this->paymentStatus;

        return 'Booking is ' . strtolower($this->bookingStatus)
             . ' and payment is ' . strtolower($payment) . '.';
    }
}
