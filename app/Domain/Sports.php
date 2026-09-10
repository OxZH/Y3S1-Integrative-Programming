<?php
// The sports a venue can be registered for. Author: Goh Jian Yu

namespace App\Domain;

// A venue is registered for exactly one sport, and every event held there plays
// that sport. An owner with a hall that does badminton and basketball registers
// it twice, once per sport, because a booking has to know which game is being
// played on the floor.
//
// Keeping the list here rather than letting owners type freely is what makes
// that rule enforceable. Free text gave us "Badminton", "badminton" and
// "Badmintonn" as three different sports, and nothing could then check that an
// event matched its venue.
//
// Adding a sport is one line. Nothing reads this except the two forms and the
// validator, so a new entry needs no other change.
class Sports
{
    const ALL = [
        'Badminton',
        'Basketball',
        'Cricket',
        'Football',
        'Frisbee',
        'Futsal',
        'Handball',
        'Hockey',
        'Netball',
        'Pickleball',
        'Rugby',
        'Sepak Takraw',
        'Squash',
        'Table Tennis',
        'Tennis',
        'Volleyball',
    ];

    public static function isKnown($sport)
    {
        return in_array($sport, Sports::ALL, true);
    }
}
