<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A post scheduled for WhatsApp groups/channels and/or the Facebook Page.
 *
 * @property int $id
 * @property string $body
 * @property string|null $image_path
 * @property list<int>|null $whatsapp_target_ids
 * @property bool $to_facebook
 * @property CarbonInterface $scheduled_at UTC
 * @property string $repeat none | weekly | monthly
 * @property string $status scheduled | sending | sent | partial | failed | cancelled
 * @property list<array{name: string, ok: bool, error: ?string}>|null $results
 * @property CarbonInterface|null $sent_at
 * @property int|null $user_id
 */
#[Fillable(['body', 'image_path', 'whatsapp_target_ids', 'to_facebook', 'scheduled_at', 'repeat', 'status', 'results', 'sent_at', 'user_id'])]
class ScheduledPost extends Model
{
    public const REPEATS = ['none' => 'Once', 'weekly' => 'Every week', 'monthly' => 'Every month'];

    protected function casts(): array
    {
        return [
            'whatsapp_target_ids' => 'array',
            'to_facebook' => 'boolean',
            'scheduled_at' => 'datetime',
            'results' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @param Builder<ScheduledPost> $query */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', 'scheduled')->where('scheduled_at', '<=', now());
    }

    /** The time in the team's timezone (Kenya by default). */
    public function localTime(): CarbonInterface
    {
        return $this->scheduled_at->copy()->setTimezone((string) config('worship.timezone'));
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'sent' => 'green',
            'partial' => 'amber',
            'failed' => 'red',
            'sending' => 'blue',
            'cancelled' => 'zinc',
            default => 'indigo',
        };
    }

    public function destinationCount(): int
    {
        return count($this->whatsapp_target_ids ?? []) + ($this->to_facebook ? 1 : 0);
    }
}
