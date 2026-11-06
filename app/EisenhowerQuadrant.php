<?php

namespace App;

enum EisenhowerQuadrant: string
{
    case DO_FIRST = 'Do_First';
    case SCHEDULE = 'Schedule';
    case DELEGATE = 'Delegate';
    case ELIMINATE = 'Eliminate';

    public function label(): string
    {
        return match ($this) {
            self::DO_FIRST => 'Do first',
            self::SCHEDULE => 'Schedule',
            self::DELEGATE => 'Delegate',
            self::ELIMINATE => 'Eliminate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DO_FIRST => 'Urgent & important',
            self::SCHEDULE => 'Important, not urgent',
            self::DELEGATE => 'Urgent, not important',
            self::ELIMINATE => 'Neither urgent nor important',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DO_FIRST => 'red',
            self::SCHEDULE => 'blue',
            self::DELEGATE => 'amber',
            self::ELIMINATE => 'zinc',
        };
    }
}
