<?php

if (! function_exists('getColor')) {
    /**
     * Map a 0-100 progress value to a Flux colour name.
     */
    function getColor(int $progress): string
    {
        return match (true) {
            $progress < 25 => 'red',
            $progress < 50 => 'orange',
            $progress < 75 => 'yellow',
            default => 'green',
        };
    }
}
