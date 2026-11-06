<?php

namespace App\Models;

use App\Contributions\ContributionLedger;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received from a member: a monthly contribution (for a given
 * year/month), the yearly registration fee, or an arising contribution.
 *
 * @property int $id
 * @property int $member_id
 * @property PaymentType $type
 * @property int $year
 * @property int|null $month
 * @property int|null $special_contribution_id
 * @property string $amount
 * @property CarbonInterface $paid_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property-read Member|null $member
 * @property-read SpecialContribution|null $specialContribution
 */
#[Fillable(['member_id', 'type', 'year', 'month', 'special_contribution_id', 'amount', 'paid_on', 'method', 'reference', 'notes', 'recorded_by'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<SpecialContribution, $this> */
    public function specialContribution(): BelongsTo
    {
        return $this->belongsTo(SpecialContribution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function forLabel(): string
    {
        return match ($this->type) {
            PaymentType::MONTHLY => ContributionLedger::monthName((int) $this->month, 'F').' '.$this->year,
            PaymentType::REGISTRATION => 'Registration '.$this->year,
            PaymentType::SPECIAL => $this->specialContribution->title ?? 'Arising contribution',
        };
    }
}
