<?php
// Turns a written address into coordinates. Author: Ng Jing Siang

declare(strict_types=1);

namespace App\Domain;

/**
 * Nominatim (OpenStreetMap's own geocoding service) turned into one call that
 * cannot fail loudly.
 *
 * Why this exists: sorting events by how far they are from a player, and
 * recommending the near ones, both need the player as a pair of coordinates.
 * What the player actually types is an address. Geocoder (the class next door)
 * only knows a fixed table of city names, which is enough to place a venue but
 * not a home address, so this asks OpenStreetMap instead and keeps that table
 * as the last resort.
 *
 * Three attempts, widest match wins, in this order:
 *
 *   1. the whole address as typed        - exact when the building is mapped
 *   2. everything after the street number - the road, taman or suburb
 *   3. Geocoder::locate() on the tail     - the fixed city table, never fails
 *
 * A residential house number is usually not in OpenStreetMap, so step 1 often
 * misses and step 2 answers; a named building (a school, a mall) is normally
 * found at step 1. Either way the result is good enough to sort by distance,
 * and deliberately no better than that: a player's exact position is personal
 * data, and api/user.php rounds it again before it leaves the account module.
 *
 * Nothing here throws. Geocoding is a convenience: an account must still be
 * created when OpenStreetMap is slow, rate limiting us, or simply wrong.
 */
final class AddressGeocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    // Nominatim's usage policy asks for an identifying User-Agent and no more
    // than one request a second. Both are honoured below.
    private const USER_AGENT = 'SportsPlatform/1.0 (BMIT3173 student project)';
    private const PAUSE_BETWEEN_CALLS_US = 1_100_000;

    private const CONNECT_TIMEOUT = 3;
    private const TIMEOUT = 5;

    /**
     * @return array{0:float,1:float}|null [latitude, longitude], or null when
     *         even the city table could not place it
     */
    public function locate(string $address): ?array
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        $attempts = [$address];
        $withoutNumber = $this->withoutStreetNumber($address);

        if ($withoutNumber !== null && $withoutNumber !== $address) {
            $attempts[] = $withoutNumber;
        }

        foreach ($attempts as $index => $query) {
            if ($index > 0) {
                usleep(self::PAUSE_BETWEEN_CALLS_US);
            }

            $coordinates = $this->ask($query);

            if ($coordinates !== null) {
                return $coordinates;
            }
        }

        return $this->fromCityTable($address);
    }

    /**
     * "77, Lorong Lembah Permai 3, 11200 Tanjung Bungah" without the leading
     * house number, which is the part OpenStreetMap is least likely to hold.
     */
    private function withoutStreetNumber(string $address): ?string
    {
        $parts = array_map('trim', explode(',', $address));

        if (count($parts) < 2) {
            return null;
        }

        // Only drop the first piece when it really is just a number, so
        // "Taman Melawati, Kuala Lumpur" keeps both halves.
        if (preg_match('/^(no\.?\s*)?\d+[a-z]?$/i', $parts[0]) !== 1) {
            return null;
        }

        array_shift($parts);

        return implode(', ', $parts);
    }

    /** @return array{0:float,1:float}|null */
    private function ask(string $query): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'q'              => $query,
            'format'         => 'json',
            'limit'          => 1,
            'countrycodes'   => 'my',
            'addressdetails' => 0,
        ]);

        $handle = curl_init($url);

        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body     = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_errno($handle) !== 0 ? curl_error($handle) : null;

        curl_close($handle);

        if ($error !== null || $httpCode !== 200 || !is_string($body)) {
            error_log(sprintf('Geocoding "%s" failed: %s', $query, $error ?? ('HTTP ' . $httpCode)));

            return null;
        }

        $results = json_decode($body, true);

        if (!is_array($results) || $results === [] || !isset($results[0]['lat'], $results[0]['lon'])) {
            return null;
        }

        $latitude  = (float) $results[0]['lat'];
        $longitude = (float) $results[0]['lon'];

        // A hit outside Malaysia means the query matched something unrelated.
        if ($latitude < 0.5 || $latitude > 7.5 || $longitude < 99.0 || $longitude > 120.0) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * Last resort: the fixed city table Facility onboarding already uses. The
     * address is read from the back, because "…, 11200 Tanjung Bungah, Pulau
     * Pinang" ends with the state and the piece before it is the town.
     *
     * @return array{0:float,1:float}|null
     */
    private function fromCityTable(string $address): ?array
    {
        $parts = array_reverse(array_map('trim', explode(',', $address)));

        foreach ($parts as $part) {
            // Strip a leading postcode: "11200 Tanjung Bungah" -> "Tanjung Bungah".
            $part = trim(preg_replace('/^\d{5}\s*/', '', $part) ?? $part);

            if ($part === '') {
                continue;
            }

            if (!Geocoder::isRough($part, $part)) {
                return Geocoder::locate($part, $part);
            }
        }

        return null;
    }
}
