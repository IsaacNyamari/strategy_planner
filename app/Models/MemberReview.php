<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A behaviour note on a member (mainly during probation).
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $user_id
 * @property string $kind positive | concern | note
 * @property string $note
 */
#[Fillable(['member_id', 'user_id', 'kind', 'note'])]
class MemberReview extends Model
{
    public const KINDS = ['positive' => 'Positive', 'concern' => 'Concern', 'note' => 'Note'];

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function color(): string
    {
        return match ($this->kind) {
            'positive' => 'green',
            'concern' => 'red',
            default => 'zinc',
        };
    }
}
