<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $meeting_id
 * @property int $member_id
 * @property string $status
 * @property string|null $note
 * @property-read Member|null $member
 * @property-read Meeting|null $meeting
 */
#[Fillable(['meeting_id', 'member_id', 'status', 'note'])]
class MeetingAttendance extends Model
{
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
