<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * The audit trail. Every create / update / delete of the app's records is
 * logged automatically, as are logins, failed logins and downloads.
 */
class Audit
{
    /** Models that are not worth (or must not be) logged. */
    protected const SKIP = [AuditLog::class, WhatsappMessage::class];

    /** Never store these values. */
    protected const SECRET = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    /** Changes to only these don't count as an edit. */
    protected const NOISE = ['updated_at', 'created_at', 'last_login_at', 'remember_token', 'current_progress'];

    public static function register(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: App\\Models\\*", function (string $name, array $payload) use ($event) {
                $model = $payload[0] ?? null;
                if ($model instanceof Model && ! in_array($model::class, self::SKIP, true)) {
                    self::model($event, $model);
                }
            });
        }

        Event::listen(function (Login $e) {
            if ($e->user instanceof User) {
                $e->user->forceFill(['last_login_at' => now()])->saveQuietly();
                self::record('login', 'Logged in', user: $e->user);
            }
        });
        Event::listen(fn (Logout $e) => $e->user instanceof User ? self::record('logout', 'Logged out', user: $e->user) : null);
        Event::listen(fn (Failed $e) => self::record('login_failed', 'Failed login for '.Str::limit((string) ($e->credentials['email'] ?? 'unknown'), 80), user: $e->user instanceof User ? $e->user : null, anonymous: true));
    }

    public static function model(string $event, Model $model): void
    {
        $changes = null;

        if ($event === 'updated') {
            $changed = array_diff(array_keys($model->getChanges()), self::NOISE);
            if ($changed === []) {
                return;
            }
            $changes = [];
            foreach ($changed as $key) {
                $secret = in_array($key, self::SECRET, true);
                $changes[$key] = [
                    'old' => $secret ? '•••' : self::scalar($model->getOriginal($key)),
                    'new' => $secret ? '•••' : self::scalar($model->getAttribute($key)),
                ];
            }
        } elseif ($event === 'created') {
            $changes = collect($model->getAttributes())
                ->except([...self::SECRET, ...self::NOISE, 'id'])
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => ['old' => null, 'new' => self::scalar($v)])
                ->all();
        }

        $type = Str::headline(class_basename($model));
        $label = self::label($model);

        self::record($event, ucfirst($event).' '.Str::lower($type).($label ? ' “'.$label.'”' : ''), $model, $changes ?: null);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     */
    public static function record(string $event, string $description, ?Model $subject = null, ?array $changes = null, ?User $user = null, bool $anonymous = false): void
    {
        try {
            $user ??= $anonymous ? null : auth()->user();
            $request = app()->runningInConsole() ? null : request();

            AuditLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'event' => $event,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject ? Str::limit(self::label($subject), 250) : null,
                'description' => Str::limit($description, 250),
                'changes' => $changes,
                'via' => match (true) {
                    $request === null => 'system',
                    $request->is('webhooks/*') => 'webhook',
                    default => 'web',
                },
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'url' => $request ? Str::limit($request->is('livewire*/update') ? (string) $request->header('Referer') : $request->fullUrl(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            report($e); // never let the audit trail break the action itself
        }
    }

    protected static function label(Model $model): string
    {
        foreach (['name', 'title', 'description', 'body', 'email'] as $attr) {
            $value = $model->getAttribute($attr);
            if (is_string($value) && $value !== '') {
                return Str::limit($value, 80);
            }
        }

        return '#'.$model->getKey();
    }

    protected static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            is_array($value) || is_object($value) => Str::limit((string) json_encode($value), 300),
            is_string($value) => Str::limit($value, 300),
            default => $value,
        };
    }
}
