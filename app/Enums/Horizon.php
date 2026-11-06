<?php

namespace App\Enums;

enum Horizon: string
{
    case LONG_TERM = 'long_term';
    case SHORT_TERM = 'short_term';

    public function label(): string
    {
        return match ($this) {
            self::LONG_TERM => 'Long term',
            self::SHORT_TERM => 'Short term',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LONG_TERM => 'violet',
            self::SHORT_TERM => 'sky',
        };
    }
}
