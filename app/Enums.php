<?php
// Value lists matching the ENUM columns in schema.sql. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App;

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
