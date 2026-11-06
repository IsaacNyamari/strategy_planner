<?php

namespace App\Enums;

enum MemberStatus: string
{
    case PROBATION = 'probation';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::PROBATION => 'On probation',
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PROBATION => 'amber',
            self::ACTIVE => 'green',
            self::INACTIVE => 'zinc',
        };
    }

    /** Probation and active members can be rostered; inactive cannot. */
    public function canMinister(): bool
    {
        return $this !== self::INACTIVE;
    }
}
