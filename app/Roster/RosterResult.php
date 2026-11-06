<?php

namespace App\Roster;

class RosterResult
{
    public int $created = 0;

    public int $staffed = 0;

    /** @var list<string> */
    public array $warnings = [];

    public function summary(): string
    {
        $parts = [];

        if ($this->created) {
            $parts[] = "{$this->created} services created";
        }

        $parts[] = "{$this->staffed} services staffed";

        if ($this->warnings) {
            $parts[] = count($this->warnings).' warnings';
        }

        return implode(', ', $parts).'.';
    }
}
