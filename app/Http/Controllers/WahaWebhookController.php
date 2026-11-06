<?php

namespace App\Http\Controllers;

use App\Models\WhatsappMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Receives WAHA events: delivery receipts (message.ack) and session status.
 * Requests must carry a valid HMAC (X-Webhook-Hmac, sha512 of the raw body).
 */
class WahaWebhookController extends Controller
{
    /** WAHA ack levels: ERROR -1, PENDING 0, SERVER 1, DEVICE 2, READ 3, PLAYED 4. */
    protected const STATUS_BY_ACK = [-1 => 'failed', 1 => 'sent', 2 => 'delivered', 3 => 'read', 4 => 'read'];

    protected const RANK = ['failed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('worship.whatsapp.webhook_secret');
        $signature = (string) $request->header('X-Webhook-Hmac');

        if ($secret === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            return response()->json(['ok' => false], 401);
        }

        $event = (string) $request->input('event');
        $payload = (array) $request->input('payload', []);

        if (in_array($event, ['message.ack', 'message.ack.group'], true)) {
            $this->recordAck($payload);
        }

        if ($event === 'session.status' && isset($payload['status'])) {
            Cache::put('whatsapp:session-status', (string) $payload['status'], now()->addDay());
        }

        return response()->json(['ok' => true]);
    }

    /** @param array<mixed> $payload */
    protected function recordAck(array $payload): void
    {
        $id = is_array($payload['id'] ?? null) ? ($payload['id']['_serialized'] ?? null) : ($payload['id'] ?? null);
        $status = self::STATUS_BY_ACK[(int) ($payload['ack'] ?? 0)] ?? null;

        if (! is_string($id) || $id === '' || $status === null) {
            return;
        }

        // Only move forward (sent -> delivered -> read); never downgrade.
        WhatsappMessage::where('waha_id', $id)->get()->each(function (WhatsappMessage $message) use ($status) {
            if ($status === 'failed' || self::RANK[$status] > (self::RANK[$message->status] ?? 0)) {
                $message->update(['status' => $status]);
            }
        });
    }
}
