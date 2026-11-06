<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $direction out | in
 * @property int|null $member_id
 * @property CarbonInterface|null $read_at
 * @property int|null $whatsapp_target_id
 * @property string $chat_id
 * @property string $target_name
 * @property string $kind
 * @property string $body
 * @property string|null $attachment
 * @property string $status sent | delivered | read | failed
 * @property string|null $waha_id
 * @property string|null $error
 * @property int|null $user_id
 */
#[Fillable(['direction', 'member_id', 'read_at', 'whatsapp_target_id', 'chat_id', 'target_name', 'kind', 'body', 'attachment', 'status', 'waha_id', 'error', 'user_id'])]
class WhatsappMessage extends Model
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<WhatsappTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(WhatsappTarget::class, 'whatsapp_target_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function color(): string
    {
        return match ($this->status) {
            'read' => 'green',
            'delivered' => 'blue',
            'sent', 'received' => 'zinc',
            default => 'red',
        };
    }
}
