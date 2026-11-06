<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An arising contribution: uniform fund, bereavement support, trip, etc.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $amount_per_member
 * @property string|null $target_amount
 * @property CarbonInterface|null $due_date
 * @property bool $is_open
 */
#[Fillable(['title', 'description', 'amount_per_member', 'target_amount', 'due_date', 'is_open'])]
class SpecialContribution extends Model
{
    protected function casts(): array
    {
        return [
            'amount_per_member' => 'decimal:2',
            'target_amount' => 'decimal:2',
            'due_date' => 'date',
            'is_open' => 'boolean',
        ];
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
