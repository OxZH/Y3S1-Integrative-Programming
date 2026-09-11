<?php
// Works out a Malaysian town and state from a postcode. Author: Goh Jian Yu

namespace App\Domain;

// Pos Malaysia gives every postcode one post town in one state, so a five digit
// code is enough to fill in both. That is why the venue form asks for the
// postcode and nothing else about where the venue is: city and state are shown
// back as read-only, and whatever the browser submits for them is thrown away.
// A combination like "Alor Setar, Terengganu" cannot be stored because it can
// no longer be typed or posted.
//
// The list lives in public/data/postcodes.json, built from the postcode data
// published at github.com/heiswayi/malaysia-postcodes, with the four names Pos
// Malaysia writes differently (Pulau Pinang, and the three Wp territories)
// renamed once to match the rest of this system.
//
// It sits under public/ because the venue form fetches the same file to fill
// the two fields in while the owner types. One copy, so the browser and the
// server can never disagree about what a postcode means.
//
// This replaced a table of postcode ranges per state. The ranges agreed with
// this data on 2,930 of 2,932 postcodes, and where they differed the ranges
// were wrong: Bandar Baharu is in Kedah rather than Penang or Perak, and 55555
// belongs to Subang Jaya in Selangor rather than to Kuala Lumpur. Ranges also
// could never name the town, which is the part the form actually needed.
class Postcodes
{
    // Read once per request and kept, which is all the caching a 96 KB file
    // needs. Nothing here writes, so a second request re-reading it is fine.
    private static $map = null;

    /** @return array<string,array{0:string,1:string}> */
    private static function map()
    {
        if (Postcodes::$map === null) {
            $file = dirname(__DIR__, 2) . '/public/data/postcodes.json';
            $raw  = is_file($file) ? (string) file_get_contents($file) : '';
            $rows = json_decode($raw, true);

            Postcodes::$map = is_array($rows) ? $rows : [];
        }

        return Postcodes::$map;
    }

    // The town and state a postcode belongs to, or null when it belongs to
    // none. Null is the answer for "that is not a Malaysian postcode", which is
    // why the form can refuse one without a separate check.
    //
    // @return array{city:string,state:string}|null
    public static function lookup($postcode)
    {
        if (preg_match('/^\d{5}$/', (string) $postcode) !== 1) {
            return null;
        }

        $map = Postcodes::map();
        $row = $map[(string) $postcode] ?? null;

        if (!is_array($row) || count($row) < 2) {
            return null;
        }

        return ['city' => (string) $row[0], 'state' => (string) $row[1]];
    }

    public static function stateFor($postcode)
    {
        $found = Postcodes::lookup($postcode);

        return $found === null ? null : $found['state'];
    }

    public static function cityFor($postcode)
    {
        $found = Postcodes::lookup($postcode);

        return $found === null ? null : $found['city'];
    }

    // How many postcodes are loaded. Only used by the check script, so a broken
    // or half-written data file shows up as a number rather than as venues that
    // quietly stop saving.
    public static function count()
    {
        return count(Postcodes::map());
    }
}
