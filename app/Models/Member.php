<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\MemberStatus;
use App\Enums\MinistryRole;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A praise & worship team member (not every member needs a login).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property Gender $gender
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $part
 * @property bool $can_lead_praise
 * @property bool $can_lead_worship
 * @property bool $is_active kept in sync with status (probation + active = can be rostered)
 * @property MemberStatus $status
 * @property CarbonInterface|null $joined_at
 * @property CarbonInterface|null $probation_ends_at
 * @property CarbonInterface|null $confirmed_at
 * @property string|null $notes
 */
#[Fillable(['user_id', 'name', 'gender', 'phone', 'email', 'part', 'can_lead_praise', 'can_lead_worship', 'is_active', 'status', 'joined_at', 'probation_ends_at', 'confirmed_at', 'notes'])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'status' => MemberStatus::class,
            'can_lead_praise' => 'boolean',
            'can_lead_worship' => 'boolean',
            'is_active' => 'boolean',
            'joined_at' => 'date',
            'probation_ends_at' => 'date',
            'confirmed_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Member $member) {
            $member->joined_at ??= now();

            // A new probation without an end date gets the team's default length.
            if ($member->status === MemberStatus::PROBATION && ! $member->probation_ends_at) {
                $member->probation_ends_at = $member->joined_at->copy()->addWeeks(Setting::probationWeeks());
            }

            $member->is_active = $member->status->canMinister();
        });
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<MemberReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(MemberReview::class)->latest();
    }

    // --- Contact ---------------------------------------------------------

    /**
     * Always store phone numbers in international format (+2547...).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Phone::normalize($value) ?? ($value ?: null));
    }

    public function phoneDisplay(): string
    {
        return Phone::format($this->phone);
    }

    public function whatsappUrl(?string $text = null): ?string
    {
        return $this->phone ? Phone::whatsappUrl($this->phone, $text) : null;
    }

    public function smsUrl(?string $text = null): ?string
    {
        return $this->phone ? Phone::smsUrl($this->phone, $text) : null;
    }

    public function telUrl(): ?string
    {
        return $this->phone ? Phone::telUrl($this->phone) : null;
    }

    // --- Probation ------------------------------------------------------

    public function onProbation(): bool
    {
        return $this->status === MemberStatus::PROBATION;
    }

    /** Days until probation ends (negative = overdue for a decision). */
    public function probationDaysLeft(): ?int
    {
        return $this->onProbation() && $this->probation_ends_at
            ? (int) today()->diffInDays($this->probation_ends_at, false)
            : null;
    }

    /** Probation finished but nobody has confirmed or discontinued the member yet. */
    public function probationDue(): bool
    {
        return $this->onProbation() && ($this->probationDaysLeft() ?? 1) <= 0;
    }

    /** 0-100: how far through probation the member is. */
    public function probationProgress(): int
    {
        if (! $this->onProbation() || ! $this->probation_ends_at || ! $this->joined_at) {
            return 0;
        }

        $total = max(1, $this->joined_at->diffInDays($this->probation_ends_at));

        return (int) max(0, min(100, round($this->joined_at->diffInDays(today()) / $total * 100)));
    }

    public function extendProbation(int $weeks): void
    {
        $from = $this->probation_ends_at && $this->probation_ends_at->isFuture() ? $this->probation_ends_at : today();
        $this->update(['status' => MemberStatus::PROBATION, 'probation_ends_at' => $from->copy()->addWeeks($weeks)]);
    }

    public function confirm(): void
    {
        $this->update(['status' => MemberStatus::ACTIVE, 'confirmed_at' => today()]);
    }

    public function discontinue(): void
    {
        $this->update(['status' => MemberStatus::INACTIVE]);
    }

    /** @param Builder<Member> $query */
    public function scopeProbationers(Builder $query): void
    {
        $query->where('status', MemberStatus::PROBATION->value);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MemberUnavailability, $this> */
    public function unavailabilities(): HasMany
    {
        return $this->hasMany(MemberUnavailability::class);
    }

    /** @return BelongsToMany<Ministration, $this> */
    public function ministrations(): BelongsToMany
    {
        return $this->belongsToMany(Ministration::class, 'ministration_member')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /** @return HasMany<Objective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class, 'owner_id');
    }

    /** @param Builder<Member> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isUnavailableOn(CarbonInterface $date): bool
    {
        return $this->unavailabilities()->whereDate('date', $date->toDateString())->exists();
    }

    /** Can lead at least one session (praise or worship). */
    public function canLead(?MinistryRole $role = null): bool
    {
        // Members on probation serve as backups only.
        if ($role !== MinistryRole::BACKUP && $this->status !== MemberStatus::ACTIVE) {
            return false;
        }

        return match ($role) {
            MinistryRole::PRAISE_LEAD => $this->can_lead_praise,
            MinistryRole::WORSHIP_LEAD => $this->can_lead_worship,
            MinistryRole::BACKUP => true,
            null => $this->can_lead_praise || $this->can_lead_worship,
        };
    }

    /** e.g. "Praise & worship", "Worship", or null. */
    public function leadLabel(): ?string
    {
        return match (true) {
            $this->can_lead_praise && $this->can_lead_worship => 'Praise & worship',
            $this->can_lead_praise => 'Praise',
            $this->can_lead_worship => 'Worship',
            default => null,
        };
    }

    public function initials(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
    }
}
