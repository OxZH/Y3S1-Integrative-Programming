<?php
// The sports a venue can be registered for. Author: Goh Jian Yu

namespace App\Domain;

use App\Sport;

// A venue is registered for exactly one sport, and every event held there plays
// that sport. An owner with a hall that does badminton and basketball registers
// it twice, once per sport, because a booking has to know which game is being
// played on the floor.
//
// Picking from a list rather than typing freely is what makes that rule
// enforceable. Free text gave us "Badminton", "badminton" and "Badmintonn" as
// three different sports, and nothing could then check that an event matched
// its venue.
//
// The list itself is App\Sport, which is shared by the whole team. This class
// used to keep a second copy and the two drifted apart, so a player could
// favourite Swimming or Golf while no venue was allowed to offer either, and a
// venue could be registered for Frisbee while no player could favourite it.
// Reading the enum means one vocabulary across venues, events and the sports a
// player follows.
//
// Adding a sport is one line in App\Sport and nothing else changes.
class Sports
{
    /** @return string[] */
    public static function all()
    {
        return Sport::values();
    }

    public static function isKnown($sport)
    {
        return in_array($sport, Sport::values(), true);
    }
}
