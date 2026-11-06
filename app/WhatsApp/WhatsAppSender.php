<?php

namespace App\WhatsApp;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Illuminate\Support\Collection;

/**
 * Sends a message (optionally with a PDF) to the chosen groups/channels and
 * logs every send. Channels get the text only: WAHA documents posting
 * text/images/video to channels, not documents.
 */
class WhatsAppSender
{
    public function __construct(protected Waha $waha) {}

    /**
     * @param  list<int>  $targetIds
     * @param  array{filename: string, content: string}|null  $attachment
     * @return Collection<int, WhatsappMessage>
     */
    public function send(array $targetIds, string $text, string $kind, ?array $attachment = null, ?User $user = null): Collection
    {
        $text = trim($text);

        return WhatsappTarget::whereIn('id', $targetIds)->orderBy('name')->get()
            ->map(function (WhatsappTarget $target) use ($text, $kind, $attachment, $user) {
                $withFile = $attachment !== null && ! $target->isChannel();

                $log = new WhatsappMessage([
                    'whatsapp_target_id' => $target->id,
                    'chat_id' => $target->chat_id,
                    'target_name' => $target->name,
                    'kind' => $kind,
                    'body' => $text,
                    'attachment' => $withFile ? $attachment['filename'] : null,
                    'user_id' => $user?->id,
                ]);

                try {
                    // Text first (it carries the announcement), then the PDF.
                    $log->waha_id = $this->waha->sendText($target->chat_id, $text);

                    if ($withFile) {
                        $this->waha->sendFile($target->chat_id, $attachment['filename'], $attachment['content'], $attachment['filename']);
                    }

                    $log->status = 'sent';

                    if ($attachment !== null && $target->isChannel()) {
                        $log->error = 'PDF not posted: channels accept text only.';
                    }
                } catch (WahaException $e) {
                    $log->status = 'failed';
                    $log->error = $e->getMessage();
                }

                $log->save();

                return $log;
            });
    }
}
