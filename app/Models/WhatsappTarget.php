<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A WhatsApp group or channel the team sends announcements to.
 *
 * @property int $id
 * @property string $name
 * @property string $chat_id
 * @property string $type group | channel
 * @property bool $is_default
 */
#[Fillable(['name', 'chat_id', 'type', 'is_default'])]
class WhatsappTarget extends Model
{
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return HasMany<WhatsappMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function isChannel(): bool
    {
        return $this->type === 'channel';
    }
}
