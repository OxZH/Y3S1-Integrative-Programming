<?php
// Value lists matching the ENUM columns in schema.sql. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

declare(strict_types=1);

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

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
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

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
