<?php
// Input validation. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Security;

use App\ValidationException;
use BackedEnum;
use DateTimeImmutable;

// validate() returns only the fields that had a rule and passed it, so a request
// carrying extras - a hopeful &status=PUBLISHED on an event form - cannot reach
// the entity.
final class Validator
{
    private $errors = [];

    private $clean = [];

    public function __construct(private array $input)
    {
    }

    public function required(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            $this->fail($field, $label . ' is required.');
        }

        return $this;
    }

    public function text(string $field, string $label, int $min = 1, int $max = 255): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        // Characters, not bytes, so a name with accents is not wrongly rejected.
        $length = mb_strlen($value);

        if ($length < $min) {
            return $this->fail($field, sprintf('%s must be at least %d characters.', $label, $min));
        }

        if ($length > $max) {
            return $this->fail($field, sprintf('%s must be %d characters or fewer.', $label, $max));
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return $this->fail($field, $label . ' contains characters that are not allowed.');
        }

        $this->clean[$field] = $value;

        return $this;
    }

    /**
     * Normalised to lower case, because an address is stored once and matched
     * on at every sign-in - "Aisyah@..." and "aisyah@..." must not become two
     * accounts that both look correct to their owner.
     */
    public function email(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (mb_strlen($value) > 255) {
            return $this->fail($field, $label . ' must be 255 characters or fewer.');
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail($field, 'Please enter a valid ' . strtolower($label) . '.');
        }

        $this->clean[$field] = mb_strtolower($value);

        return $this;
    }

    /** Digits, with an optional leading +. Spaces and dashes are dropped, not rejected. */
    public function phone(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        $compact = preg_replace('/[\s()-]/', '', $value) ?? '';

        if (preg_match('/^\+?\d{7,19}$/', $compact) !== 1) {
            return $this->fail($field, $label . ' must be 7 to 19 digits, optionally starting with +.');
        }

        $this->clean[$field] = $compact;

        return $this;
    }

    /**
     * A password is passed through unchanged - not trimmed, not case folded,
     * not length checked here. Strength is PasswordPolicy's decision, and
     * trimming would silently alter what the user chose.
     */
    public function password(string $field, string $label): self
    {
        $value = $this->input[$field] ?? null;

        if (!is_string($value) || $value === '') {
            return $this->fail($field, $label . ' is required.');
        }

        $this->clean[$field] = $value;

        return $this;
    }

    public function integer(string $field, string $label, ?int $min = null, ?int $max = null): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $this->fail($field, $label . ' must be a whole number.');
        }

        $number = (int) $value;

        if ($min !== null && $number < $min) {
            return $this->fail($field, sprintf('%s must be at least %d.', $label, $min));
        }

        if ($max !== null && $number > $max) {
            return $this->fail($field, sprintf('%s must be %d or less.', $label, $max));
        }

        $this->clean[$field] = $number;

        return $this;
    }

    public function decimal(string $field, string $label, ?float $min = null, ?float $max = null): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
            return $this->fail($field, $label . ' must be a number.');
        }

        $number = (float) $value;

        if ($min !== null && $number < $min) {
            return $this->fail($field, sprintf('%s cannot be less than %s.', $label, $min));
        }

        if ($max !== null && $number > $max) {
            return $this->fail($field, sprintf('%s cannot be more than %s.', $label, $max));
        }

        $this->clean[$field] = round($number, 2);

        return $this;
    }

    // $maxAhead is a relative date such as '+3 months'. Left null the date may
    // be any time in the future, which is what the other modules want.
    public function date(
        string $field,
        string $label,
        bool $mustBeFuture = false,
        ?string $maxAhead = null
    ): self {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return $this->fail($field, $label . ' must be a valid date.');
        }

        if ($mustBeFuture && $parsed < new DateTimeImmutable('today')) {
            return $this->fail($field, $label . ' cannot be in the past.');
        }

        if ($maxAhead !== null) {
            $limit = new DateTimeImmutable('today ' . $maxAhead);

            if ($parsed > $limit) {
                return $this->fail($field, sprintf(
                    '%s cannot be later than %s.',
                    $label,
                    $limit->format('d M Y')
                ));
            }
        }

        $this->clean[$field] = $parsed;

        return $this;
    }

    // Accepts HH:MM or HH:MM:SS, always stores HH:MM:SS to match the TIME columns.
    //
    // Pass false for $allowMidnight on an end time. 00:00 reads as "the start of
    // this day", so an event ending at midnight would finish before it began and
    // every duration calculated from it comes out negative.
    public function time(string $field, string $label, bool $allowMidnight = true): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $value, $m) !== 1) {
            return $this->fail($field, $label . ' must be a valid time.');
        }

        if (!$allowMidnight && $m[1] === '00' && $m[2] === '00') {
            return $this->fail(
                $field,
                $label . ' cannot be midnight. An event has to finish on the day it starts, '
                       . 'so please choose a time up to 23:59.'
            );
        }

        $this->clean[$field] = sprintf('%s:%s:%s', $m[1], $m[2], $m[4] ?? '00');

        return $this;
    }

    public function enum(string $field, string $label, string $enumClass): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        $case = $enumClass::tryFrom($value);

        if ($case === null) {
            return $this->fail($field, 'Please choose a valid ' . strtolower($label) . '.');
        }

        $this->clean[$field] = $case;

        return $this;
    }

    // A record id arriving from a form. Real keys are UUIDs, the seeded demo
    // rows use ids like fac-001, so this allows both shapes and nothing else -
    // anything with a quote or a space in it never reaches a query.
    public function identifier(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (preg_match('/^[A-Za-z0-9-]{1,36}$/', $value) !== 1) {
            return $this->fail($field, 'Please choose a valid ' . strtolower($label) . '.');
        }

        $this->clean[$field] = $value;

        return $this;
    }

    // A Malaysian street address, comma separated:
    //
    //   1-2-2, Taman Setiawangsa, Jalan Genting Klang, 53300
    //   ^ unit   ^ taman/building   ^ street (optional)  ^ postcode
    //
    // The unit, the area and the postcode are needed. The street is not: plenty
    // of places are known only by their taman or their building name. So both
    // three parts and four parts are accepted.
    //
    // "No." and "Lot" are allowed in front of the number because that is how
    // most shoplots are written. Without this rule something like ?????12-3012
    // counts as an address, which is no use to anyone trying to find the venue.
    public function address(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        // One piece of a unit number: 12, 12A, A7, or a bare block letter such
        // as the A in A-3-7, which is how most condo units are written.
        $piece  = '(?:[A-Za-z]?\d+[A-Za-z]?|[A-Za-z])';
        $number = '(?:(?:No|Lot|Unit|Blok|Block)\.?\s*)?' . $piece . '(?:-' . $piece . ')*';

        $words    = '[A-Za-z][A-Za-z0-9.\'\- ]+';
        $comma    = '\s*,\s*';
        $postcode = '\d{5}';

        $pattern = '/^' . $number            // 1-2-2
                 . $comma . $words           // Taman Setiawangsa
                 . '(?:' . $comma . $words . ')?'   // Jalan Genting Klang, if there is one
                 . $comma . $postcode        // 53300
                 . '$/';

        if (preg_match($pattern, $value) !== 1) {
            return $this->fail($field, $label . ' must look like "1-2-2, Taman Setiawangsa, '
                                             . 'Jalan Genting Klang, 53300". The street name can be '
                                             . 'left out, but the unit, the area and a 5 digit '
                                             . 'postcode are needed.');
        }

        $this->clean[$field] = $value;

        return $this;
    }

    // A place name: letters, and the punctuation that turns up in real names
    // such as Batu Caves or George Town. No digits, so "12345" is not a city.
    public function placeName(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (preg_match('/^[A-Za-z][A-Za-z.\'\- ]+$/', $value) !== 1) {
            return $this->fail($field, $label . ' can only contain letters, spaces and hyphens.');
        }

        $this->clean[$field] = $value;

        return $this;
    }

    // The value has to be one of the ones we offered. Used for the state
    // dropdown: a dropdown stops a mistake in the browser, this stops one sent
    // straight to the server.
    public function inList(string $field, string $label, array $allowed): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (!in_array($value, $allowed, true)) {
            return $this->fail($field, 'Please choose a valid ' . strtolower($label) . '.');
        }

        $this->clean[$field] = $value;

        return $this;
    }

    public function latitude(string $field, string $label): self
    {
        return $this->coordinate($field, $label, -90.0, 90.0);
    }

    public function longitude(string $field, string $label): self
    {
        return $this->coordinate($field, $label, -180.0, 180.0);
    }

    // Blocks javascript: and data: URLs, which would turn a src attribute into XSS.
    public function imageUrl(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if ($scheme === '' && str_starts_with($value, '/')) {
            $this->clean[$field] = $value;

            return $this;
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->fail($field, $label . ' must be an http(s) address or a path beginning with /.');
        }

        $this->clean[$field] = $value;

        return $this;
    }

    public function timeAfter(string $startField, string $endField, string $label): self
    {
        $start = $this->clean[$startField] ?? null;
        $end   = $this->clean[$endField] ?? null;

        if (is_string($start) && is_string($end) && $end <= $start) {
            $this->fail($endField, $label . ' must be later than the start time.');
        }

        return $this;
    }

    // The date and the start time together must still be ahead of us.
    //
    // date(..., mustBeFuture) only compares whole days, so it lets today
    // through - which is right, people do organise a game for this evening. But
    // on its own it also lets through 09:00 today when it is already 21:00. This
    // is the check that catches that.
    public function notInThePast(string $dateField, string $timeField, string $label): self
    {
        $date = $this->clean[$dateField] ?? null;
        $time = $this->clean[$timeField] ?? null;

        if (!$date instanceof DateTimeImmutable || !is_string($time)) {
            return $this;
        }

        $starts = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $time);

        if ($starts < new DateTimeImmutable()) {
            $this->fail($timeField, $label . ' has already passed. Please pick a later time.');
        }

        return $this;
    }

    public function atLeast(string $minField, string $maxField, string $label): self
    {
        $min = $this->clean[$minField] ?? null;
        $max = $this->clean[$maxField] ?? null;

        if (is_int($min) && is_int($max) && $max < $min) {
            $this->fail($maxField, $label . ' cannot be smaller than the minimum.');
        }

        return $this;
    }

    public function validate(): array
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->clean;
    }

    private function coordinate(string $field, string $label, float $min, float $max): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
            return $this->fail($field, $label . ' must be a number.');
        }

        $number = (float) $value;

        if ($number < $min || $number > $max) {
            return $this->fail($field, sprintf('%s must be between %s and %s.', $label, $min, $max));
        }

        $this->clean[$field] = $number;

        return $this;
    }

    private function raw(string $field): ?string
    {
        $value = $this->input[$field] ?? null;

        if (is_array($value) || is_object($value)) {
            $this->fail($field, 'Unexpected value submitted.');

            return null;
        }

        return $value === null ? null : trim((string) $value);
    }

    private function fail(string $field, string $message): self
    {
        // Keep the first failure per field; later ones are usually a consequence.
        $this->errors[$field] ??= $message;

        unset($this->clean[$field]);

        return $this;
    }
}
