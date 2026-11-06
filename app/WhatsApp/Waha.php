<?php

namespace App\WhatsApp;

use App\Support\Phone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for a self-hosted WAHA server (https://waha.devlike.pro/docs/).
 * WAHA runs on this server only (127.0.0.1), protected by an API key.
 *
 * Response shapes differ slightly between WAHA engines, so ids and lists are
 * normalised defensively.
 */
class Waha
{
    public function enabled(): bool
    {
        return (bool) config('worship.whatsapp.enabled') && filled(config('worship.whatsapp.api_key'));
    }

    public function sessionName(): string
    {
        return (string) config('worship.whatsapp.session', 'default');
    }

    /** The team number in WAHA's format (digits only, international), e.g. 254711777992. */
    public function phoneDigits(): ?string
    {
        $phone = Phone::normalize((string) config('worship.whatsapp.number'));

        return $phone ? ltrim($phone, '+') : null;
    }

    // --- Session ------------------------------------------------------------------

    /**
     * The session, or null if it doesn't exist yet.
     *
     * @return array{name: string, status: string, me: array{id: ?string, pushName: ?string}|null}|null
     *
     * @throws WahaException
     */
    public function session(): ?array
    {
        $response = $this->http('get', '/api/sessions/'.$this->sessionName());

        if ($response->status() === 404) {
            return null;
        }

        $data = $this->json($response);
        $me = is_array($data['me'] ?? null) ? $data['me'] : null;

        return [
            'name' => (string) ($data['name'] ?? $this->sessionName()),
            'status' => (string) ($data['status'] ?? 'UNKNOWN'),
            'me' => $me ? ['id' => isset($me['id']) ? $this->id($me['id']) : null, 'pushName' => $me['pushName'] ?? null] : null,
        ];
    }

    /**
     * Create the session (with our webhook) if missing, and make sure it's started.
     *
     * @throws WahaException
     */
    public function ensureStarted(?string $webhookUrl = null): void
    {
        $session = $this->session();

        if ($session === null) {
            $config = [];

            if ($webhookUrl) {
                $config['webhooks'] = [$this->webhookConfig($webhookUrl)];
            }

            $this->json($this->http('post', '/api/sessions', [
                'name' => $this->sessionName(),
                'config' => (object) $config,
            ]));

            return;
        }

        if (in_array($session['status'], ['STOPPED', 'FAILED'], true)) {
            $this->json($this->http('post', '/api/sessions/'.$this->sessionName().'/start'));
        }
    }

    /** Webhook events the app relies on (incoming chats, receipts, status). */
    public const WEBHOOK_EVENTS = ['message', 'message.ack', 'session.status'];

    /**
     * Make sure an existing session's webhook has our URL and every event we
     * need, so features added later (e.g. incoming chats) start working.
     * Returns true if the session had to be updated.
     *
     * @throws WahaException
     */
    public function syncWebhook(string $webhookUrl): bool
    {
        $response = $this->http('get', '/api/sessions/'.$this->sessionName());

        if ($response->status() === 404) {
            return false;
        }

        $config = (array) ($this->json($response)['config'] ?? []);
        $hooks = array_values(array_filter((array) ($config['webhooks'] ?? []), 'is_array'));
        $ours = collect($hooks)->firstWhere('url', $webhookUrl);

        if ($ours && ! array_diff(self::WEBHOOK_EVENTS, (array) ($ours['events'] ?? []))) {
            return false; // already correct
        }

        $config['webhooks'] = [
            ...array_values(array_filter($hooks, fn ($h) => ($h['url'] ?? null) !== $webhookUrl)),
            $this->webhookConfig($webhookUrl),
        ];

        $this->json($this->http('put', '/api/sessions/'.$this->sessionName(), [
            'name' => $this->sessionName(),
            'config' => $config,
        ]));

        return true;
    }

    /** @return array<string, mixed> */
    protected function webhookConfig(string $url): array
    {
        return [
            'url' => $url,
            'events' => self::WEBHOOK_EVENTS,
            'hmac' => ['key' => (string) config('worship.whatsapp.webhook_secret')],
            'retries' => ['policy' => 'constant', 'delaySeconds' => 5, 'attempts' => 3],
        ];
    }

    /**
     * QR code to scan from WhatsApp > Linked devices, as a data: URI.
     *
     * @throws WahaException
     */
    public function qr(): ?string
    {
        $response = $this->http('get', '/api/'.$this->sessionName().'/auth/qr');
        $data = $this->json($response);

        return isset($data['data'], $data['mimetype']) ? 'data:'.$data['mimetype'].';base64,'.$data['data'] : null;
    }

    /**
     * Pairing code to type in WhatsApp > Linked devices > Link with phone number.
     *
     * @throws WahaException
     */
    public function requestPairingCode(): string
    {
        $phone = $this->phoneDigits() ?? throw new WahaException('No valid WHATSAPP_NUMBER is configured.');
        $data = $this->json($this->http('post', '/api/'.$this->sessionName().'/auth/request-code', ['phoneNumber' => $phone]));

        return (string) ($data['code'] ?? throw new WahaException('WhatsApp did not return a pairing code. Try the QR code instead.'));
    }

    /** @throws WahaException */
    public function logout(): void
    {
        $this->json($this->http('post', '/api/sessions/'.$this->sessionName().'/logout'));
    }

    // --- Groups & channels --------------------------------------------------------------

    /**
     * Groups the linked number belongs to.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws WahaException
     */
    public function groups(): array
    {
        $data = $this->json($this->http('get', '/api/'.$this->sessionName().'/groups', [
            'exclude' => 'participants',
            'sortBy' => 'subject',
            'sortOrder' => 'asc',
        ]));

        $groups = [];

        foreach ($this->items($data) as $key => $group) {
            $id = $this->id($group['id'] ?? $group['JID'] ?? (is_string($key) ? $key : null));

            if ($id && str_ends_with($id, '@g.us')) {
                $groups[] = ['id' => $id, 'name' => (string) ($group['subject'] ?? $group['name'] ?? $group['Name'] ?? $id)];
            }
        }

        return $groups;
    }

    /**
     * Channels the linked number owns or administers (only those can be posted to).
     *
     * @return list<array{id: string, name: string, role: string}>
     *
     * @throws WahaException
     */
    public function channels(): array
    {
        $data = $this->json($this->http('get', '/api/'.$this->sessionName().'/channels'));
        $channels = [];

        foreach ($this->items($data) as $channel) {
            $id = $this->id($channel['id'] ?? null);
            $role = strtoupper((string) ($channel['role'] ?? ''));

            if ($id && str_ends_with($id, '@newsletter') && in_array($role, ['OWNER', 'ADMIN'], true)) {
                $channels[] = ['id' => $id, 'name' => (string) ($channel['name'] ?? $id), 'role' => $role];
            }
        }

        return $channels;
    }

    // --- Sending --------------------------------------------------------------------------

    /**
     * @return string|null WAHA's message id (used to match delivery receipts)
     *
     * @throws WahaException
     */
    public function sendText(string $chatId, string $text): ?string
    {
        return $this->messageId($this->json($this->http('post', '/api/sendText', [
            'session' => $this->sessionName(),
            'chatId' => $chatId,
            'text' => $text,
            'linkPreview' => false,
        ])));
    }

    /** @throws WahaException */
    public function sendFile(string $chatId, string $filename, string $content, string $caption = '', string $mimetype = 'application/pdf'): ?string
    {
        return $this->messageId($this->json($this->http('post', '/api/sendFile', [
            'session' => $this->sessionName(),
            'chatId' => $chatId,
            'caption' => $caption,
            'file' => ['mimetype' => $mimetype, 'filename' => $filename, 'data' => base64_encode($content)],
        ])));
    }

    // --- One-to-one chats -------------------------------------------------------

    /**
     * The chat id to message a phone number, or null if it isn't on WhatsApp.
     *
     * @throws WahaException
     */
    public function chatIdFor(string $phone): ?string
    {
        $digits = ltrim((string) Phone::normalize($phone), '+');
        if ($digits === '') {
            return null;
        }

        $data = $this->json($this->http('get', '/api/contacts/check-exists', ['phone' => $digits, 'session' => $this->sessionName()]));

        return ($data['numberExists'] ?? false) ? ($this->id($data['chatId'] ?? null) ?? $digits.'@c.us') : null;
    }

    /** Phone number (international, with +) behind a hidden @lid id, if WhatsApp shares it. */
    public function phoneForLid(string $lid): ?string
    {
        try {
            $data = $this->json($this->http('get', '/api/'.$this->sessionName().'/lids/'.rawurlencode($lid)));
        } catch (WahaException) {
            return null;
        }

        $pn = $this->id($data['pn'] ?? null);

        return $pn ? '+'.strtok($pn, '@') : null;
    }

    /** Show "typing…" for a moment before sending, as WAHA recommends. */
    public function typing(string $chatId, int $seconds = 2): void
    {
        try {
            $this->http('post', '/api/'.$this->sessionName().'/presence', ['chatId' => $chatId, 'presence' => 'typing']);
            sleep($seconds);
            $this->http('post', '/api/'.$this->sessionName().'/presence', ['chatId' => $chatId, 'presence' => 'paused']);
        } catch (WahaException) {
            // Presence is cosmetic; never block a send on it.
        }
    }

    /** @throws WahaException */
    public function sendSeen(string $chatId): void
    {
        $this->http('post', '/api/sendSeen', ['session' => $this->sessionName(), 'chatId' => $chatId]);
    }

    // --- Internals ------------------------------------------------------------------------

    /**
     * Every call goes through here so "WAHA is down" becomes a friendly error.
     *
     * @param  array<mixed>  $data
     *
     * @throws WahaException
     */
    protected function http(string $method, string $uri, array $data = []): Response
    {
        try {
            return match ($method) {
                'get' => $this->request()->get($uri, $data ?: null),
                'put' => $this->request()->put($uri, $data),
                default => $this->request()->post($uri, $data),
            };
        } catch (ConnectionException) {
            throw new WahaException('Cannot reach the WhatsApp service. Is the WAHA container running?');
        }
    }

    protected function request(): PendingRequest
    {
        if (! $this->enabled()) {
            throw new WahaException('WhatsApp is not set up on this server yet.');
        }

        return Http::baseUrl((string) config('worship.whatsapp.url'))
            ->withHeaders(['X-Api-Key' => (string) config('worship.whatsapp.api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('worship.whatsapp.timeout', 30))
            ->connectTimeout(5);
    }

    /**
     * @return array<mixed>
     *
     * @throws WahaException
     */
    protected function json(Response $response): array
    {
        if ($response->status() === 401) {
            throw new WahaException('The WhatsApp service rejected the API key (check WAHA_API_KEY).');
        }

        if ($response->failed()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();
            throw new WahaException('WhatsApp error ('.$response->status().'): '.mb_substr(is_string($message) ? $message : (string) json_encode($message), 0, 300));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Lists come back as a JSON array or (on some engines) an object keyed by id.
     *
     * @param  array<mixed>  $data
     * @return array<int|string, array<string, mixed>>
     */
    protected function items(array $data): array
    {
        return array_filter($data, 'is_array');
    }

    /** Ids are plain strings on some engines and {"_serialized": "..."} on others. */
    protected function id(mixed $id): ?string
    {
        if (is_array($id)) {
            $id = $id['_serialized'] ?? $id['id'] ?? null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @param array<mixed> $data */
    protected function messageId(array $data): ?string
    {
        return $this->id($data['id'] ?? null) ?? $this->id($data['key']['id'] ?? null) ?? $this->id($data['_data']['id'] ?? null);
    }
}
