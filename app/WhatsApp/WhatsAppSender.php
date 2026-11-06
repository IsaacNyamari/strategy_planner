<?php

namespace App\WhatsApp;

use App\Models\Member;
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
     * Message one member directly, one at a time (no bulk: WhatsApp restricts
     * mass messaging to individuals). Shows "typing…" first, as WAHA advises.
     */
    public function sendToMember(Member $member, string $text, ?User $user = null): WhatsappMessage
    {
        $log = new WhatsappMessage([
            'direction' => 'out',
            'member_id' => $member->id,
            'chat_id' => (string) $member->phone,
            'target_name' => $member->name,
            'kind' => 'chat',
            'body' => trim($text),
            'user_id' => $user?->id,
        ]);

        try {
            if (! $member->phone) {
                throw new WahaException($member->name.' has no phone number.');
            }

            // Reuse the chat we already have with them (e.g. their reply came from a hidden @lid id).
            $chatId = WhatsappMessage::where('member_id', $member->id)->where('direction', 'in')->latest()->value('chat_id')
                ?? $this->waha->chatIdFor($member->phone)
                ?? throw new WahaException($member->phoneDisplay().' is not on WhatsApp.');

            $log->chat_id = $chatId;
            $this->waha->typing($chatId);
            $log->waha_id = $this->waha->sendText($chatId, trim($text));
            $log->status = 'sent';
        } catch (WahaException $e) {
            $log->status = 'failed';
            $log->error = $this->explain($e->getMessage());
        }

        $log->save();

        return $log;
    }

    /** WhatsApp's anti-spam limits on new contacts (see WAHA "how to avoid blocking"). */
    protected function explain(string $error): string
    {
        return match (true) {
            str_contains($error, '463') => 'WhatsApp is temporarily limiting messages to new contacts (error 463). Ask them to message the team number first, or try later.',
            str_contains($error, '475') => 'WhatsApp message cap reached for new contacts (error 475). Existing chats still work; try later.',
            default => $error,
        };
    }

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
