<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team meeting: agenda, attendance register, minutes and action items.
 *
 * @property int $id
 * @property string $title
 * @property string $type
 * @property CarbonInterface $date
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $venue
 * @property string|null $agenda
 * @property string|null $minutes
 * @property string $status
 * @property int|null $chaired_by
 * @property int|null $minutes_by
 * @property int|null $created_by
 * @property-read Member|null $chair
 * @property-read Member|null $minuteTaker
 */
#[Fillable(['title', 'type', 'date', 'starts_at', 'ends_at', 'venue', 'agenda', 'minutes', 'status', 'chaired_by', 'minutes_by', 'created_by'])]
class Meeting extends Model
{
    public const TYPES = ['general' => 'General meeting', 'committee' => 'Committee meeting', 'special' => 'Special meeting', 'prayer' => 'Prayer meeting'];

    public const ATTENDANCE = ['present' => 'Present', 'late' => 'Late', 'apology' => 'Apology', 'absent' => 'Absent'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return HasMany<MeetingAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    /** @return HasMany<MeetingAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(MeetingAction::class)->orderBy('done_at')->orderBy('due_date');
    }

    /** @return BelongsTo<Member, $this> */
    public function chair(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'chaired_by');
    }

    /** @return BelongsTo<Member, $this> */
    public function minuteTaker(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'minutes_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function timeLabel(): string
    {
        $fmt = fn (?string $t) => $t ? date('g:i A', (int) strtotime($t)) : null;

        return collect([$fmt($this->starts_at), $fmt($this->ends_at)])->filter()->implode(' – ');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'held' => 'green',
            'cancelled' => 'red',
            default => 'blue',
        };
    }
}
