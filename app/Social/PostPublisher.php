<?php

namespace App\Social;

use App\Models\ScheduledPost;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\WhatsApp\Waha;
use App\WhatsApp\WahaException;
use Illuminate\Support\Facades\Storage;

/**
 * Sends a scheduled post to each of its destinations and records the result.
 */
class PostPublisher
{
    public function __construct(protected Waha $waha, protected FacebookPage $facebook) {}

    /** Publish every post whose time has come. Returns how many were processed. */
    public function publishDue(): int
    {
        $count = 0;

        foreach (ScheduledPost::due()->orderBy('scheduled_at')->limit(20)->pluck('id') as $id) {
            $post = ScheduledPost::query()->whereKey($id)->first();
            if ($post && $this->publish($post)) {
                $count++;
            }
        }

        return $count;
    }

    /** Returns false if another run already took this post. */
    public function publish(ScheduledPost $post): bool
    {
        // Claim it atomically so overlapping runs never send twice.
        $claimed = ScheduledPost::whereKey($post->id)->whereIn('status', ['scheduled'])->update(['status' => 'sending']);
        if (! $claimed) {
            return false;
        }

        $post->refresh();
        $results = [];
        $image = $post->image_path && Storage::disk('public')->exists($post->image_path)
            ? ['name' => basename($post->image_path), 'content' => Storage::disk('public')->get($post->image_path), 'mime' => Storage::disk('public')->mimeType($post->image_path) ?: 'image/jpeg']
            : null;
        $audio = $post->audio_path && Storage::disk('public')->exists($post->audio_path)
            ? ['name' => $post->audio_name ?: basename($post->audio_path), 'content' => (string) Storage::disk('public')->get($post->audio_path), 'mime' => Storage::disk('public')->mimeType($post->audio_path) ?: 'audio/mpeg']
            : null;

        foreach (WhatsappTarget::whereIn('id', $post->whatsapp_target_ids ?? [])->orderBy('name')->get() as $target) {
            $log = new WhatsappMessage([
                'whatsapp_target_id' => $target->id,
                'chat_id' => $target->chat_id,
                'target_name' => $target->name,
                'kind' => 'scheduled',
                'body' => $post->body,
                'attachment' => collect([$image['name'] ?? null, $audio['name'] ?? null])->filter()->implode(', ') ?: null,
                'user_id' => $post->user_id,
            ]);

            try {
                $log->waha_id = $image
                    ? $this->waha->sendImage($target->chat_id, $image['name'], (string) $image['content'], $post->body, $image['mime'])
                    : $this->waha->sendText($target->chat_id, $post->body);
                if ($audio) {
                    $this->sendAudio($target->chat_id, $audio);
                }
                $log->status = 'sent';
                $results[] = ['name' => 'WhatsApp: '.$target->name, 'ok' => true, 'error' => null];
            } catch (WahaException $e) {
                $log->status = 'failed';
                $log->error = $e->getMessage();
                $results[] = ['name' => 'WhatsApp: '.$target->name, 'ok' => false, 'error' => $e->getMessage()];
            }

            $log->save();
        }

        if ($post->to_facebook) {
            try {
                $this->facebook->publish($post->body, $post->imageUrl());
                $results[] = ['name' => 'Facebook Page', 'ok' => true, 'error' => null];
            } catch (WahaException $e) {
                $results[] = ['name' => 'Facebook Page', 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $ok = collect($results)->where('ok', true)->count();
        $post->update([
            'status' => match (true) {
                $results === [] => 'failed',
                $ok === count($results) => 'sent',
                $ok > 0 => 'partial',
                default => 'failed',
            },
            'results' => $results ?: [['name' => 'No destinations', 'ok' => false, 'error' => 'None of the chosen destinations exist any more.']],
            'sent_at' => now(),
        ]);

        $this->scheduleNext($post);

        return true;
    }

    /**
     * Send the clip as a voice note; if WhatsApp/WAHA can't convert it, send it
     * as an audio file instead so it still arrives.
     *
     * @param  array{name: string, content: string, mime: string}  $audio
     *
     * @throws WahaException
     */
    protected function sendAudio(string $chatId, array $audio): void
    {
        try {
            $this->waha->sendVoice($chatId, $audio['name'], $audio['content'], $audio['mime']);
        } catch (WahaException) {
            $this->waha->sendFile($chatId, $audio['name'], $audio['content'], '', $audio['mime']);
        }
    }

    /** Weekly / monthly posts: queue the next occurrence. */
    protected function scheduleNext(ScheduledPost $post): void
    {
        if ($post->repeat === 'none') {
            return;
        }

        $next = $post->localTime();
        $next = $post->repeat === 'weekly' ? $next->addWeek() : $next->addMonthNoOverflow();

        $post->replicate(['status', 'results', 'sent_at'])->fill([
            'scheduled_at' => $next->setTimezone((string) config('app.timezone')),
            'status' => 'scheduled',
        ])->save();
    }
}
