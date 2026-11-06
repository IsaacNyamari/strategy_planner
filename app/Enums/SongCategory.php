<?php

namespace App\Enums;

enum SongCategory: string
{
    case PRAISE = 'praise';
    case WORSHIP = 'worship';
    case HYMN = 'hymn';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::PRAISE => 'amber',
            self::WORSHIP => 'violet',
            self::HYMN => 'emerald',
        };
    }
}
