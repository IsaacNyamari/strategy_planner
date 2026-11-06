<?php

namespace App\Enums;

enum SongLanguage: string
{
    case ENGLISH = 'english';
    case SWAHILI = 'swahili';

    public function label(): string
    {
        return match ($this) {
            self::ENGLISH => 'English',
            self::SWAHILI => 'Kiswahili',
        };
    }
}
