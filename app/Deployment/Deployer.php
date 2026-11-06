<?php

namespace App\Deployment;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Talks to the root-owned deploy helper (deploy/web-deploy.sh) that the
 * Deployments page's button triggers. The helper does the actual work in its
 * own systemd unit; this class only starts it and reads its state and log.
 */
class Deployer
{
    public function enabled(): bool
    {
        return (bool) config('worship.deploy.enabled');
    }

    public function allows(?User $user): bool
    {
        return $this->enabled()
            && $user !== null
            && in_array(strtolower($user->email), config('worship.deploy.admins', []), true);
    }

    /**
     * Start a deployment of the latest code on GitHub.
     *
     * @return array{ok: bool, message: string}
     */
    public function start(User $user): array
    {
        if ($this->state()['status'] === 'running') {
            return ['ok' => false, 'message' => 'A deployment is already running.'];
        }

        $result = Process::timeout(30)->run([...$this->command(), 'run', $user->email]);

        if ($result->exitCode() === 3) {
            return ['ok' => false, 'message' => 'A deployment is already running.'];
        }

        if (! $result->successful()) {
            return ['ok' => false, 'message' => 'Could not start the deployment: '.trim($result->errorOutput() ?: $result->output() ?: 'unknown error')];
        }

        Cache::forget('deploy:versions');

        return ['ok' => true, 'message' => 'Deployment started.'];
    }

    /**
     * Live commit vs latest commit on GitHub (cached briefly).
     *
     * @return array{current: array{hash: string, message: string, date: string}, latest: string}|null
     */
    public function versions(bool $fresh = false): ?array
    {
        if ($fresh) {
            Cache::forget('deploy:versions');
        }

        return Cache::remember('deploy:versions', now()->addMinute(), function (): ?array {
            $result = Process::timeout(20)->run([...$this->command(), 'status']);
            $data = $result->successful() ? json_decode($result->output(), true) : null;

            if (! is_array($data) || ! is_array($data['current'] ?? null) || blank($data['current']['hash'] ?? null)) {
                return null;
            }

            return [
                'current' => [
                    'hash' => (string) $data['current']['hash'],
                    'message' => (string) ($data['current']['message'] ?? ''),
                    'date' => (string) ($data['current']['date'] ?? ''),
                ],
                'latest' => (string) ($data['latest'] ?? ''),
            ];
        });
    }

    /**
     * Last deployment: status (idle|running|success|failed), times, commit, who.
     *
     * @return array{status: string, started_at: ?string, finished_at: ?string, exit_code: ?int, commit: ?string, requested_by: ?string}
     */
    public function state(): array
    {
        $default = ['status' => 'idle', 'started_at' => null, 'finished_at' => null, 'exit_code' => null, 'commit' => null, 'requested_by' => null];
        $path = storage_path('app/deploy-state.json');

        if (! File::exists($path)) {
            return $default;
        }

        $data = json_decode((string) File::get($path), true);

        if (! is_array($data)) {
            return $default;
        }

        $text = fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;

        return [
            'status' => in_array($data['status'] ?? null, ['running', 'success', 'failed'], true) ? $data['status'] : 'idle',
            'started_at' => $text('started_at'),
            'finished_at' => $text('finished_at'),
            'exit_code' => isset($data['exit_code']) && is_numeric($data['exit_code']) ? (int) $data['exit_code'] : null,
            'commit' => $text('commit'),
            'requested_by' => $text('requested_by'),
        ];
    }

    /** The end of the deployment log, without terminal colour codes. */
    public function log(int $maxBytes = 60000): string
    {
        $path = storage_path('logs/deploy.log');

        if (! File::exists($path)) {
            return '';
        }

        $size = (int) File::size($path);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return '';
        }

        if ($size > $maxBytes) {
            fseek($handle, -$maxBytes, SEEK_END);
        }

        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
    }

    /** @return list<string> */
    protected function command(): array
    {
        return array_values(array_filter(explode(' ', (string) config('worship.deploy.command'))));
    }
}
