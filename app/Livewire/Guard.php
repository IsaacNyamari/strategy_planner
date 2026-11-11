<?php

namespace App\Livewire;

use Attribute;

/**
 * Put on a Livewire page: every action (button / form submit) needs this
 * permission, except the read-only ones listed in $read. Viewing the page
 * itself is protected by the route's can: middleware.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Guard
{
    /** @param  list<string>  $read */
    public function __construct(public string $permission, public array $read = []) {}
}
