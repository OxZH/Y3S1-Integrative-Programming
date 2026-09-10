<?php
// Facility entity. Author: Goh Jian Yu

namespace App\Model;

use App\Core\Entity;
use App\FacilityStatus;
use DateTimeImmutable;

// A venue that can be booked for an event. One of the two entities this module
// owns, and the one the other modules read the most.
//
// The owner is kept as an Account object and not an ownerId string, because the
// class diagram draws a relationship and not a foreign key. It is only fetched
// when something actually asks for it, see Entity.
//
// Opening and closing times are stored as 'H:i:s' strings. In that format a
// plain string comparison already gives the right answer, so '09:00:00' is
// correctly seen as earlier than '22:00:00'.
class Facility extends Entity
{
    private $facilityId;
    private $name;
    private $addressLine;
    private $city;
    private $state;
    private $type;
    private $bookingFee;
    private $operationalHrsStart;
    private $operationalHrsEnd;
    private $latitude;
    private $longitude;
    private $status;
    private $imageUrl;
    private $createdAt;

    // protected and not private, so the lazy loader in Entity can reach it
    protected $owner = null;

    public function __construct(
        $facilityId,
        $name,
        $addressLine,
        $city,
        $state,
        $type,
        $bookingFee,
        $operationalHrsStart,
        $operationalHrsEnd,
        $latitude,
        $longitude,
        FacilityStatus $status = FacilityStatus::PENDING,
        $imageUrl = null,
        DateTimeImmutable $createdAt = null
    ) {
        $this->facilityId = $facilityId;
        $this->name = $name;
        $this->addressLine = $addressLine;
        $this->city = $city;
        $this->state = $state;
        $this->type = $type;
        $this->bookingFee = $bookingFee;
        $this->operationalHrsStart = $operationalHrsStart;
        $this->operationalHrsEnd = $operationalHrsEnd;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->status = $status;
        $this->imageUrl = $imageUrl;
        $this->createdAt = $createdAt === null ? new DateTimeImmutable() : $createdAt;
    }

    public function getIdentity(): ?string
    {
        return $this->facilityId;
    }

    public function getFacilityId()
    {
        return $this->facilityId;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getAddressLine()
    {
        return $this->addressLine;
    }

    public function getCity()
    {
        return $this->city;
    }

    public function getState()
    {
        return $this->state;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getBookingFee()
    {
        return $this->bookingFee;
    }

    public function getOperationalHrsStart()
    {
        return $this->operationalHrsStart;
    }

    public function getOperationalHrsEnd()
    {
        return $this->operationalHrsEnd;
    }

    public function getLatitude()
    {
        return $this->latitude;
    }

    public function getLongitude()
    {
        return $this->longitude;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getImageUrl()
    {
        return $this->imageUrl;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    // the owning business, as an object
    public function getOwner()
    {
        return $this->resolve('owner');
    }

    public function setOwner(Account $owner)
    {
        $this->owner = $owner;
    }

    public function getFullAddress()
    {
        return $this->addressLine . ', ' . $this->city . ', ' . $this->state;
    }

    public function isBookable()
    {
        return $this->status->isBookable();
    }

    // asked by the availability checker before an event is allowed into a slot
    // A venue can close after midnight, eg open 22:00 and close 02:00. When it
    // does, the open period is split in two: the evening, and the early hours of
    // the next day. An event never crosses midnight, so it falls inside one half
    // or neither, and checking both with OR is enough.
    public function isWithinOperatingHours($startTime, $endTime)
    {
        if ($this->operationalHrsStart <= $this->operationalHrsEnd) {
            return $startTime >= $this->operationalHrsStart
                && $endTime <= $this->operationalHrsEnd;
        }

        return $startTime >= $this->operationalHrsStart
            || $endTime <= $this->operationalHrsEnd;
    }

    // True when the venue closes on the day after it opens.
    public function closesAfterMidnight()
    {
        return $this->operationalHrsStart > $this->operationalHrsEnd;
    }

    // straight line distance in km, using the Haversine formula
    public function distanceFromKm($latitude, $longitude)
    {
        $deltaLat = deg2rad($latitude - $this->latitude);
        $deltaLon = deg2rad($longitude - $this->longitude);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
           + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude))
           * sin($deltaLon / 2) * sin($deltaLon / 2);

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function approve()
    {
        $this->status = FacilityStatus::ACTIVE;
    }

    public function suspend()
    {
        $this->status = FacilityStatus::SUSPENDED;
    }

    // $changes comes from the Validator, so only fields that passed are in it
    public function updateDetails(array $changes)
    {
        $this->name = isset($changes['name']) ? $changes['name'] : $this->name;
        $this->addressLine = isset($changes['addressLine']) ? $changes['addressLine'] : $this->addressLine;
        $this->city = isset($changes['city']) ? $changes['city'] : $this->city;
        $this->state = isset($changes['state']) ? $changes['state'] : $this->state;
        $this->type = isset($changes['type']) ? $changes['type'] : $this->type;
        $this->bookingFee = isset($changes['bookingFee']) ? $changes['bookingFee'] : $this->bookingFee;
        $this->operationalHrsStart = isset($changes['operationalHrsStart']) ? $changes['operationalHrsStart'] : $this->operationalHrsStart;
        $this->operationalHrsEnd = isset($changes['operationalHrsEnd']) ? $changes['operationalHrsEnd'] : $this->operationalHrsEnd;
        $this->latitude = isset($changes['latitude']) ? $changes['latitude'] : $this->latitude;
        $this->longitude = isset($changes['longitude']) ? $changes['longitude'] : $this->longitude;
        $this->imageUrl = isset($changes['imageUrl']) ? $changes['imageUrl'] : $this->imageUrl;
    }
}
