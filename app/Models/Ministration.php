<?php

namespace App\Models;

use App\Enums\MinistryRole;
use App\Enums\ServiceType;
use Carbon\CarbonImmutable;
use Database\Factories\MinistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * A Sunday / midweek service, or an abrupt special meeting.
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $starts_at
 * @property string $ends_at
 * @property ServiceType $type
 * @property string|null $title
 * @property int|null $uniform_id
 * @property string|null $notes
 * @property-read Uniform|null $uniform
 */
#[Fillable(['date', 'starts_at', 'ends_at', 'type', 'title', 'uniform_id', 'notes'])]
class Ministration extends Model
{
    /** @use HasFactory<MinistrationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Ministration $m) {
            $m->starts_at = self::normalizeTime($m->starts_at);
            $m->ends_at = self::normalizeTime($m->ends_at);
        });
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => ServiceType::class,
        ];
    }

    /** @return BelongsTo<Uniform, $this> */
    public function uniform(): BelongsTo
    {
        return $this->belongsTo(Uniform::class);
    }

    /** @return BelongsToMany<Member, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'ministration_member')->withPivot('role')->withTimestamps();
    }

    /** @return BelongsToMany<Song, $this> */
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class)->withPivot('position')->withTimestamps()->orderByPivot('position');
    }

    /** The member holding a role (praise / worship lead) in this service. */
    public function leadFor(MinistryRole $role): ?Member
    {
        return $this->members->first(fn (Member $m) => $m->getRelationValue('pivot')?->role === $role->value);
    }

    public function praiseLead(): ?Member
    {
        return $this->leadFor(MinistryRole::PRAISE_LEAD);
    }

    public function worshipLead(): ?Member
    {
        return $this->leadFor(MinistryRole::WORSHIP_LEAD);
    }

    /** @return Collection<int, Member> */
    public function backups(): Collection
    {
        return $this->members->filter(fn (Member $m) => ! MinistryRole::tryFrom((string) $m->getRelationValue('pivot')?->role)?->isLead())->values();
    }

    public function displayTitle(): string
    {
        return $this->title ?: $this->type->label();
    }

    public function timeRange(): string
    {
        return substr($this->starts_at, 0, 5).' - '.substr($this->ends_at, 0, 5);
    }

    /**
     * Ministrations on the same date whose time range overlaps [start, end).
     *
     * @param  Builder<Ministration>  $query
     */
    public function scopeOverlapping(Builder $query, string $date, string $start, string $end): void
    {
        $start = self::normalizeTime($start);
        $end = self::normalizeTime($end);

        $query->whereDate('date', $date)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }

    /** Normalise "9:00" / "09:00" / "09:00:00" to "09:00:00". */
    public static function normalizeTime(string $time): string
    {
        return CarbonImmutable::createFromFormat('H:i:s', strlen($time) <= 5 ? $time.':00' : $time)->format('H:i:s');
    }

    /** @param Builder<Ministration> $query */
    public function scopeInMonth(Builder $query, int $year, int $month): void
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $query->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()]);
    }
}
