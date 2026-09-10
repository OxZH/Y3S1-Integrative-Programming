<?php
// Works out a Malaysian state from a postcode. Author: Goh Jian Yu

namespace App\Domain;

// Pos Malaysia hands out postcodes by state, so a five digit code already says
// which state a venue sits in. That means the owner never has to pick one, and
// the old problem of choosing Terengganu while typing a Kedah address simply
// cannot happen any more.
//
// A code outside every range below is not a Malaysian postcode at all, so this
// doubles as a check on the postcode itself.
//
// Some states hold more than one block. Pahang has the Genting codes away from
// its main run, and Selangor has a second block above Putrajaya.
class Postcodes
{
    const RANGES = [
        'Perlis'          => [[1000, 2800]],
        'Kedah'           => [[5000, 9810]],
        'Penang'          => [[10000, 14400]],
        'Kelantan'        => [[15000, 18500]],
        'Terengganu'      => [[20000, 24300]],
        'Pahang'          => [[25000, 28800], [39000, 39200], [49000, 49000], [69000, 69000]],
        'Perak'           => [[30000, 36810]],
        'Selangor'        => [[40000, 48300], [63000, 68100]],
        'Kuala Lumpur'    => [[50000, 60000]],
        'Putrajaya'       => [[62000, 62988]],
        'Negeri Sembilan' => [[70000, 73509]],
        'Melaka'          => [[75000, 78309]],
        'Johor'           => [[79000, 86900]],
        'Labuan'          => [[87000, 87033]],
        'Sabah'           => [[88000, 91309]],
        'Sarawak'         => [[93000, 98859]],
    ];

    // The state a postcode belongs to, or null when it belongs to none.
    public static function stateFor($postcode)
    {
        if (preg_match('/^\d{5}$/', (string) $postcode) !== 1) {
            return null;
        }

        $number = (int) $postcode;

        foreach (Postcodes::RANGES as $state => $blocks) {
            foreach ($blocks as $block) {
                if ($number >= $block[0] && $number <= $block[1]) {
                    return $state;
                }
            }
        }

        return null;
    }
}
