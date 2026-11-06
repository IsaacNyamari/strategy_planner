<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What each member owes in a given year.
 *
 * @property int $id
 * @property int $year
 * @property string $monthly_amount
 * @property string $registration_fee
 */
#[Fillable(['year', 'monthly_amount', 'registration_fee'])]
class ContributionRate extends Model
{
    protected function casts(): array
    {
        return ['monthly_amount' => 'decimal:2', 'registration_fee' => 'decimal:2'];
    }

    public static function forYear(int $year): self
    {
        // Unsaved (exists = false) with zero amounts when the year has no rates yet.
        return static::query()->firstOrNew(['year' => $year], ['monthly_amount' => 0, 'registration_fee' => 0]);
    }
}
