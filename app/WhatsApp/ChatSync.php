<?php

namespace App\WhatsApp;

use App\Models\Member;
use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;

/**
 * Pulls a member's recent WhatsApp messages (both directions, including
 * ones typed on the team phone) into the app, so a chat looks like the
 * WhatsApp inbox. Safe to run repeatedly: nothing is imported twice.
 */
class ChatSync
{
    protected const STATUS_BY_ACK = [-1 => 'failed', 0 => 'sent', 1 => 'sent', 2 => 'delivered', 3 => 'read', 4 => 'read'];

    public function __construct(protected Waha $waha) {}

    /** @return int number of messages imported */
    public function syncMember(Member $member, int $limit = 50): int
    {
        $chatId = $this->chatIdFor($member);

        if (! $chatId) {
            return 0;
        }

        $imported = 0;

        foreach ($this->waha->chatMessages($chatId, $limit) as $m) {
            if (WhatsappMessage::where('waha_id', $m['id'])->exists() || $this->alreadySentFromApp($member, $m)) {
                continue;
            }

            $at = Carbon::createFromTimestamp($m['timestamp'], (string) config('app.timezone'));

            $message = new WhatsappMessage([
                'direction' => $m['fromMe'] ? 'out' : 'in',
                'member_id' => $member->id,
                'chat_id' => $chatId,
                'target_name' => $member->name,
                'kind' => 'chat',
                'body' => $m['body'],
                'status' => $m['fromMe'] ? (self::STATUS_BY_ACK[$m['ack'] ?? 1] ?? 'sent') : 'received',
                'read_at' => $m['fromMe'] ? null : now(), // history you just opened counts as read
                'waha_id' => $m['id'],
            ]);
            $message->created_at = $at;
            $message->updated_at = $at;
            $message->save();

            $imported++;
        }

        return $imported;
    }

    protected function chatIdFor(Member $member): ?string
    {
        $known = WhatsappMessage::where('member_id', $member->id)->where('kind', 'chat')
            ->where('chat_id', 'like', '%@%')->latest('id')->value('chat_id');

        return $known ?? ($member->phone ? $this->waha->chatIdFor($member->phone) : null);
    }

    /**
     * A message sent from the app is already logged; WAHA may report it with
     * a slightly different id, so also match on text and time.
     *
     * @param  array{body: string, timestamp: int, fromMe: bool}  $m
     */
    protected function alreadySentFromApp(Member $member, array $m): bool
    {
        if (! $m['fromMe']) {
            return false;
        }

        $at = Carbon::createFromTimestamp($m['timestamp'], (string) config('app.timezone'));

        return WhatsappMessage::where('member_id', $member->id)->where('direction', 'out')
            ->where('body', $m['body'])
            ->whereBetween('created_at', [$at->copy()->subMinutes(3), $at->copy()->addMinutes(3)])
            ->exists();
    }
}
