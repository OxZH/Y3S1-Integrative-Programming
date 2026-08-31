<?php
// Builds entities from validated input. Author: Goh Jian Yu

declare(strict_types=1);

namespace App\Domain;

use App\Competitiveness;
use App\EventStatus;
use App\EventVisibility;
use App\FacilityStatus;
use App\FitnessRequirement;
use App\Model\Account;
use App\Model\Event;
use App\Model\Facility;
use App\SkillLevel;
use DateTimeImmutable;

/**
 * The arrays here have already been through Validator, so every value present is
 * the right type and every value absent was never asked for. Must never be
 * handed a raw $_POST.
 */
final class EntityFactory
{
    /** @param array<string,mixed> $validated */
    public function newFacility(array $validated, Account $owner): Facility
    {
        $facility = new Facility(
            uuid(),
            (string) $validated['name'],
            (string) $validated['addressLine'],
            (string) $validated['city'],
            (string) $validated['state'],
            (string) $validated['type'],
            (float) $validated['bookingFee'],
            (string) $validated['operationalHrsStart'],
            (string) $validated['operationalHrsEnd'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],

            // Never ACTIVE straight from a form: a venue is listed only after
            // approval, so a new account cannot publish a fake one instantly.
            FacilityStatus::PENDING,

            isset($validated['imageUrl']) ? (string) $validated['imageUrl'] : null,
            new DateTimeImmutable()
        );

        $facility->setOwner($owner);

        return $facility;
    }

    /**
     * Status is not taken from the form. Validator's allow-list already drops a
     * hopeful &status=PUBLISHED, but an event cannot start life published and
     * that is worth being explicit about.
     *
     * @param array<string,mixed> $validated
     */
    public function newEvent(array $validated, Account $host, Facility $facility): Event
    {
        $event = new Event(
            uuid(),
            (string) $validated['name'],
            (string) $validated['sport'],
            $validated['eventDate'],
            (string) $validated['startTime'],
            (string) $validated['endTime'],
            (int) $validated['minParticipants'],
            (int) $validated['maxParticipants'],
            $validated['skillLevel'] ?? SkillLevel::ANY,
            $validated['fitnessRequirement'] ?? FitnessRequirement::MODERATE,
            $validated['competitiveness'] ?? Competitiveness::CASUAL,
            $validated['visibility'] ?? EventVisibility::PUBLIC,
            EventStatus::DRAFT,
            isset($validated['feePerParticipant']) ? (float) $validated['feePerParticipant'] : 0.0,
            new DateTimeImmutable()
        );

        $event->setHost($host);
        $event->setLocation($facility);

        return $event;
    }
}
