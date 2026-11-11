<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An action item agreed in a meeting.
 *
 * @property int $id
 * @property int $meeting_id
 * @property string $description
 * @property int|null $member_id
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $done_at
 * @property-read Member|null $member
 * @property-read Meeting|null $meeting
 */
#[Fillable(['meeting_id', 'description', 'member_id', 'due_date', 'done_at'])]
class MeetingAction extends Model
{
    protected function casts(): array
    {
        return ['due_date' => 'date', 'done_at' => 'datetime'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
