<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Money in (other than member contributions) or money out, with an optional
 * scanned receipt kept on the private disk.
 *
 * @property int $id
 * @property string $direction
 * @property string $category
 * @property string $description
 * @property string|null $party
 * @property string $amount
 * @property CarbonInterface $entry_date
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property int|null $special_contribution_id
 * @property string|null $receipt_path
 * @property string|null $receipt_name
 * @property int|null $user_id
 * @property-read SpecialContribution|null $specialContribution
 */
#[Fillable(['direction', 'category', 'description', 'party', 'amount', 'entry_date', 'method', 'reference', 'special_contribution_id', 'receipt_path', 'receipt_name', 'user_id'])]
class CashEntry extends Model
{
    public const IN_CATEGORIES = ['Offering', 'Donation', 'Fundraising', 'Sales', 'Other income'];

    public const OUT_CATEGORIES = ['Uniforms', 'Instruments & sound', 'Transport', 'Refreshments', 'Welfare & support', 'Events', 'Printing & stationery', 'Airtime & data', 'Bank & M-Pesa charges', 'Other'];

    protected static function booted(): void
    {
        static::deleted(function (CashEntry $entry) {
            if ($entry->receipt_path) {
                Storage::disk('local')->delete($entry->receipt_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'entry_date' => 'date',
        ];
    }

    /** @return BelongsTo<SpecialContribution, $this> */
    public function specialContribution(): BelongsTo
    {
        return $this->belongsTo(SpecialContribution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isIncome(): bool
    {
        return $this->direction === 'in';
    }

    public function hasReceipt(): bool
    {
        return $this->receipt_path !== null && Storage::disk('local')->exists($this->receipt_path);
    }
}
