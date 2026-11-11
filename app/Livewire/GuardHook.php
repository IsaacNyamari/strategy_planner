<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Gate;
use Livewire\ComponentHook;
use ReflectionClass;

/** Enforces #[Guard] on every Livewire action call, server-side. */
class GuardHook extends ComponentHook
{
    /** @param  array<int, mixed>  $params */
    public function call(string $method, array $params, callable $returnEarly): void
    {
        if (str_starts_with($method, '$')) {
            return; // $set / $refresh / $toggle only change page state, never data
        }

        foreach ((new ReflectionClass($this->component))->getAttributes(Guard::class) as $attribute) {
            $guard = $attribute->newInstance();
            if (! in_array($method, $guard->read, true)) {
                Gate::authorize($guard->permission);
            }
        }
    }
}
