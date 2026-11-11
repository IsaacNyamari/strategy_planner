<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the audit trail: who did what, when and how.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property string $description
 * @property array<string, array{old: mixed, new: mixed}>|null $changes
 * @property string $via
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $url
 * @property CarbonInterface $created_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'user_name', 'event', 'subject_type', 'subject_id', 'subject_label', 'description', 'changes', 'via', 'ip', 'user_agent', 'url', 'created_at'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "Chrome on Windows" from the user agent. */
    public function device(): string
    {
        $ua = (string) $this->user_agent;
        if ($ua === '') {
            return $this->via === 'web' ? 'Unknown device' : ucfirst($this->via);
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone/iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown OS',
        };

        return "{$browser} on {$os}";
    }

    public function color(): string
    {
        return match ($this->event) {
            'created' => 'green',
            'updated' => 'blue',
            'deleted', 'login_failed' => 'red',
            'login', 'logout' => 'zinc',
            'downloaded' => 'violet',
            default => 'amber',
        };
    }
}
