<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case MPESA = 'mpesa';
    case CASH = 'cash';
    case BANK = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::MPESA => 'M-Pesa',
            self::CASH => 'Cash',
            self::BANK => 'Bank',
        };
    }
}
