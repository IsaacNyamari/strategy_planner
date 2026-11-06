<?php

namespace App\Enums;

enum PaymentType: string
{
    case MONTHLY = 'monthly';
    case REGISTRATION = 'registration';
    case SPECIAL = 'special';

    public function label(): string
    {
        return match ($this) {
            self::MONTHLY => 'Monthly contribution',
            self::REGISTRATION => 'Registration fee',
            self::SPECIAL => 'Arising contribution',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MONTHLY => 'blue',
            self::REGISTRATION => 'violet',
            self::SPECIAL => 'amber',
        };
    }
}
