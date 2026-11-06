<?php

namespace App;

enum TasksEnum: string
{
    case NOT_STARTED = 'Not_Started';
    case IN_PROGRESS = 'In_Progress';
    case COMPLETED = 'Completed';
    case BLOCKED = 'Blocked';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Not started',
            self::IN_PROGRESS => 'In progress',
            self::COMPLETED => 'Completed',
            self::BLOCKED => 'Blocked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'zinc',
            self::IN_PROGRESS => 'blue',
            self::COMPLETED => 'green',
            self::BLOCKED => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'stop-circle',
            self::IN_PROGRESS => 'play-circle',
            self::COMPLETED => 'check-circle',
            self::BLOCKED => 'no-symbol',
        };
    }
}
