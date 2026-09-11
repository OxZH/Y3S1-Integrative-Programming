<?php
// Value lists matching the ENUM columns in schema.sql. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App;

// ---------------------------------------------------------------------------
//  Module 2 - User Authentication & Profile Management (Ivan)
// ---------------------------------------------------------------------------

// The class table inheritance discriminator on BaseUser. The mapper reads this
// to decide which subclass to build.
enum UserType: string
{
    case USER           = 'USER';
    case FACILITY_OWNER = 'FACILITY_OWNER';
    case ADMIN          = 'ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::USER           => 'Player',
            self::FACILITY_OWNER => 'Facility owner',
            self::ADMIN          => 'Administrator',
        };
    }

    // Only these two can be chosen at registration. ADMIN is never self-service:
    // an account cannot talk itself into a staff role by posting userType=ADMIN.
    public static function registrable(): array
    {
        return [self::USER, self::FACILITY_OWNER];
    }
}

/**
 * The sports a player can pick as a favourite.
 *
 * A fixed list rather than a free text box, for two reasons. The recommendation
 * engine matches a player's favourites against an event's sport by name, and
 * "Badminton", "badminton" and "Badmintons" would score as three different
 * sports. And a closed list means the value is checked against something real
 * on the way in, so nothing arbitrary reaches the database.
 *
 * Chosen for what is actually played in Malaysia - badminton, football and
 * futsal at the top, sepak takraw and silat included, ice hockey not.
 */
enum Sport: string
{
    case BADMINTON    = 'Badminton';
    case FOOTBALL     = 'Football';
    case FUTSAL       = 'Futsal';
    case SEPAK_TAKRAW = 'Sepak Takraw';
    case BASKETBALL   = 'Basketball';
    case VOLLEYBALL   = 'Volleyball';
    case TABLE_TENNIS = 'Table Tennis';
    case TENNIS       = 'Tennis';
    case SQUASH       = 'Squash';
    case HOCKEY       = 'Hockey';
    case NETBALL      = 'Netball';
    case BOWLING      = 'Bowling';
    case SWIMMING     = 'Swimming';
    case CYCLING      = 'Cycling';
    case RUNNING      = 'Running';
    case RUGBY        = 'Rugby';
    case SILAT        = 'Silat';
    case GOLF         = 'Golf';
    case PICKLEBALL   = 'Pickleball';
    case CRICKET      = 'Cricket';
    case FRISBEE      = 'Frisbee';
    case HANDBALL     = 'Handball';

    public function label(): string
    {
        return $this->value;
    }

    /** @return string[] the stored values, for a dropdown or a validator */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}

/**
 * The bank a facility owner is paid out to.
 *
 * A fixed list for the same reason App\Sport is one: free text gave us
 * "Maybank", "maybank" and "May Bank" as three spellings of one bank, and
 * nothing downstream could match them. Picking from a list means the value is
 * checked on the way in and stored one way.
 *
 * These are the licensed retail banks in Malaysia, spelled as the bank spells
 * itself. Adding one is a single line here and nothing else changes.
 *
 * This is the OWNER'S payout bank, which is a different question from the bank
 * a participant pays FROM at checkout - that list belongs to the payment
 * module, in App\Domain\Payment\FpxPaymentStrategy.
 */
enum Bank: string
{
    case MAYBANK            = 'Maybank';
    case CIMB               = 'CIMB Bank';
    case PUBLIC_BANK        = 'Public Bank';
    case RHB                = 'RHB Bank';
    case HONG_LEONG         = 'Hong Leong Bank';
    case AMBANK             = 'AmBank';
    case BANK_ISLAM         = 'Bank Islam';
    case BANK_RAKYAT        = 'Bank Rakyat';
    case BSN                = 'Bank Simpanan Nasional';
    case AFFIN              = 'Affin Bank';
    case ALLIANCE           = 'Alliance Bank';
    case OCBC               = 'OCBC Bank';
    case HSBC               = 'HSBC Bank';
    case STANDARD_CHARTERED = 'Standard Chartered';
    case UOB                = 'UOB';
    case AGROBANK           = 'Agrobank';
    case MBSB               = 'MBSB Bank';

    public function label(): string
    {
        return $this->value;
    }

    /** @return string[] the stored values, for a dropdown or a validator */
    public static function values(): array
    {
        return array_map(static fn (self $b): string => $b->value, self::cases());
    }
}

enum AccountStatus: string
{
    case ACTIVE      = 'ACTIVE';
    case DEACTIVATED = 'DEACTIVATED';
    case SUSPENDED   = 'SUSPENDED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}

// What the audit trail records. Matches the ENUM on AuthEventLog.
enum AuthEventType: string
{
    case LOGIN_SUCCESS             = 'LOGIN_SUCCESS';
    case LOGIN_FAILED              = 'LOGIN_FAILED';
    case LOGOUT                    = 'LOGOUT';
    case ACCOUNT_LOCKED            = 'ACCOUNT_LOCKED';
    case REGISTERED                = 'REGISTERED';
    case PASSWORD_CHANGED          = 'PASSWORD_CHANGED';
    case PASSWORD_RESET_REQUESTED  = 'PASSWORD_RESET_REQUESTED';
    case PASSWORD_RESET_COMPLETED  = 'PASSWORD_RESET_COMPLETED';
    case PROFILE_UPDATED           = 'PROFILE_UPDATED';
    case ACCOUNT_DEACTIVATED       = 'ACCOUNT_DEACTIVATED';
    case ACCOUNT_REACTIVATED       = 'ACCOUNT_REACTIVATED';
    case ROLE_CHANGED              = 'ROLE_CHANGED';
    case ACCESS_DENIED             = 'ACCESS_DENIED';

    public function label(): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $this->value)));
    }
}

enum EventStatus: string
{
    case DRAFT           = 'DRAFT';
    case PENDING_PAYMENT = 'PENDING_PAYMENT';
    case PUBLISHED       = 'PUBLISHED';
    case FULL            = 'FULL';
    case ONGOING         = 'ONGOING';
    case COMPLETED       = 'COMPLETED';
    case CANCELLED       = 'CANCELLED';

    public function isPublished(): bool
    {
        return $this === self::PUBLISHED;
    }

    // True once the event has been live, and it stays true afterwards. A game
    // that filled up, is being played, has finished or was called off was still
    // a real game that real people joined, so they have to be able to see it.
    //
    // Only DRAFT and PENDING_PAYMENT are false, because those never reached
    // anybody. isPublished() above is the narrower question, "is it open for
    // joining right now", and is not the same thing.
    public function hasBeenLive(): bool
    {
        return $this !== self::DRAFT && $this !== self::PENDING_PAYMENT;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->value)));
    }
}

enum EventVisibility: string
{
    case PUBLIC       = 'PUBLIC';
    case FRIENDS_ONLY = 'FRIENDS_ONLY';

    public function label(): string
    {
        return $this === self::PUBLIC ? 'Public' : 'Friends only';
    }
}

enum FacilityStatus: string
{
    case PENDING   = 'PENDING';
    case ACTIVE    = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';

    public function isBookable(): bool
    {
        return $this === self::ACTIVE;
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}

enum SkillLevel: string
{
    case BEGINNER     = 'BEGINNER';
    case INTERMEDIATE = 'INTERMEDIATE';
    case ADVANCED     = 'ADVANCED';
    case ANY          = 'ANY';

    public function label(): string
    {
        return $this === self::ANY ? 'Any skill level' : ucfirst(strtolower($this->value));
    }
}

enum FitnessRequirement: string
{
    case LOW      = 'LOW';
    case MODERATE = 'MODERATE';
    case HIGH     = 'HIGH';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}

enum Competitiveness: string
{
    case CASUAL      = 'CASUAL';
    case COMPETITIVE = 'COMPETITIVE';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}

// ---------------------------------------------------------------------------
//  Module 5 - Discovery & Event Matchmaking (js)
// ---------------------------------------------------------------------------

// js part - Discovery & Event Matchmaking.
enum RegistrationStatus: string
{
    case PENDING   = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';
    case ATTENDED  = 'ATTENDED';
    case NO_SHOW   = 'NO_SHOW';

    // Counts toward an event's headcount and toward participation history.
    public function isActive(): bool
    {
        return $this === self::CONFIRMED || $this === self::ATTENDED;
    }
    public function label(): string
    {
        return $this === self::NO_SHOW ? 'No show' : ucfirst(strtolower($this->value));
        return ucfirst(strtolower($this->value));
    }
}

// kw part
        
enum FriendState: string
{
    case PENDING   = 'PENDING';
    case ACCEPTED  = 'ACCEPTED';
    case REJECTED  = 'REJECTED';
    case REMOVED   = 'REMOVED';

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }
}

enum ModerationStatus: string
{
    case VISIBLE   = 'VISIBLE';
    case HIDDEN    = 'HIDDEN';
    case REMOVED   = 'REMOVED';

    public function isVisible(): bool
    {
        return $this === self::VISIBLE;
    }

    public function isRemoved(): bool
    {
        return $this === self::REMOVED;
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
