<?php

namespace App\Enums;

enum ServiceType: string
{
    case SUNDAY = 'sunday';
    case WEDNESDAY = 'wednesday';
    case FRIDAY = 'friday';
    case SPECIAL = 'special';

    public function label(): string
    {
        return match ($this) {
            self::SUNDAY => 'Sunday service',
            self::WEDNESDAY => 'Wednesday midweek',
            self::FRIDAY => 'Friday midweek',
            self::SPECIAL => 'Special meeting',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SUNDAY => 'indigo',
            self::WEDNESDAY => 'teal',
            self::FRIDAY => 'amber',
            self::SPECIAL => 'rose',
        };
    }
}
