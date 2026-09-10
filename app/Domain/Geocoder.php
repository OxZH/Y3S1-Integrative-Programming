<?php
// Turns an address into map coordinates. Author: Goh Jian Yu

namespace App\Domain;

// A venue owner knows their street address, not their latitude, but the
// distance sorting and the map pins need coordinates. This works them out from
// the city and state the owner already typed.
//
// It is a lookup table for now. That puts a venue within a few km of where it
// really is, which is close enough to sort venues by distance, and an owner who
// wants it exact can still type the numbers on the form. When the OpenStreetMap
// picker from the Discovery module is ready, only locate() has to change -
// nothing outside this class knows how the coordinates were worked out.
class Geocoder
{
    // Kuala Lumpur city centre, for a city that is not in the list below.
    const FALLBACK = [3.1390, 101.6869];

    // The 13 states and 3 federal territories. There is a fixed number of them,
    // so the form offers them as a dropdown and the validator checks the answer
    // came from this list. That is simpler than a pattern and it means a state
    // like "???" cannot be saved at all.
    const STATES = [
        'Johor',
        'Kedah',
        'Kelantan',
        'Kuala Lumpur',
        'Labuan',
        'Melaka',
        'Negeri Sembilan',
        'Pahang',
        'Penang',
        'Perak',
        'Perlis',
        'Putrajaya',
        'Sabah',
        'Sarawak',
        'Selangor',
        'Terengganu',
    ];

    // City names are matched in lower case, so what the owner typed does not
    // have to match the capitals used here.
    const CITIES = [
        'kuala lumpur'    => [3.1390, 101.6869],
        'setapak'         => [3.2145, 101.7268],
        'cheras'          => [3.1050, 101.7500],
        'kepong'          => [3.2080, 101.6320],
        'wangsa maju'     => [3.2050, 101.7350],
        'petaling jaya'   => [3.1073, 101.6067],
        'shah alam'       => [3.0733, 101.5185],
        'subang jaya'     => [3.0567, 101.5851],
        'klang'           => [3.0449, 101.4455],
        'kajang'          => [2.9927, 101.7909],
        'puchong'         => [3.0198, 101.6167],
        'seri kembangan'  => [3.0246, 101.7075],
        'ampang'          => [3.1500, 101.7600],
        'rawang'          => [3.3210, 101.5770],
        'putrajaya'       => [2.9264, 101.6964],
        'cyberjaya'       => [2.9213, 101.6559],
        'seremban'        => [2.7297, 101.9381],
        'melaka'          => [2.1896, 102.2501],
        'johor bahru'     => [1.4927, 103.7414],
        'ipoh'            => [4.5975, 101.0901],
        'george town'     => [5.4141, 100.3288],
        'penang'          => [5.4141, 100.3288],
        'kuantan'         => [3.8077, 103.3260],
        'kota kinabalu'   => [5.9804, 116.0735],
        'kuching'         => [1.5533, 110.3592],
    ];

    // Returns [latitude, longitude] for the address given.
    public static function locate($city, $state)
    {
        $key = strtolower(trim((string) $city));

        if (isset(Geocoder::CITIES[$key])) {
            return Geocoder::CITIES[$key];
        }

        // Some owners put the bigger city in the state box, eg city "Setapak",
        // state "Kuala Lumpur", so it is worth a second look.
        $key = strtolower(trim((string) $state));

        if (isset(Geocoder::CITIES[$key])) {
            return Geocoder::CITIES[$key];
        }

        return Geocoder::FALLBACK;
    }

    // True when the address was not recognised, so the form can say the pin is
    // only a rough guess and offer to have it corrected.
    public static function isRough($city, $state)
    {
        return Geocoder::locate($city, $state) === Geocoder::FALLBACK;
    }
}
