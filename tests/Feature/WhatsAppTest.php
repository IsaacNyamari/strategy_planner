<?php

use App\Models\ContributionRate;
use App\Models\Member;
use App\Models\Ministration;
use App\Models\Payment;
use App\Models\SpecialContribution;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\WhatsApp\MessageTemplates;
use App\WhatsApp\Waha;
use App\WhatsApp\WahaException;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const WAHA = 'http://127.0.0.1:3100';

beforeEach(function () {
    config([
        'worship.whatsapp.enabled' => true,
        'worship.whatsapp.url' => WAHA,
        'worship.whatsapp.api_key' => 'test-key',
        'worship.whatsapp.session' => 'default',
        'worship.whatsapp.number' => '0711777992',
        'worship.whatsapp.webhook_secret' => 'hook-secret',
        'worship.admins' => ['admin@example.com'],
    ]);
});

function group(string $name = 'Praise Team'): WhatsappTarget
{
    return WhatsappTarget::create(['name' => $name, 'chat_id' => '120363000000000001@g.us', 'type' => 'group', 'is_default' => true]);
}

function channel(): WhatsappTarget
{
    return WhatsappTarget::create(['name' => 'Worship Channel', 'chat_id' => '120363999999999999@newsletter', 'type' => 'channel', 'is_default' => true]);
}

// --- Client ----------------------------------------------------------------------------

test('the client sends the api key and normalises groups from either list shape', function () {
    Http::fake([
        WAHA.'/api/default/groups*' => Http::sequence()
            ->push([['id' => ['_serialized' => '111@g.us'], 'subject' => 'Praise Team'], ['id' => '222@g.us', 'name' => 'Choir']])
            ->push(['333@g.us' => ['JID' => '333@g.us', 'Name' => 'Ushers']]),
    ]);

    $waha = app(Waha::class);

    expect($waha->groups())->toBe([['id' => '111@g.us', 'name' => 'Praise Team'], ['id' => '222@g.us', 'name' => 'Choir']])
        ->and($waha->groups())->toBe([['id' => '333@g.us', 'name' => 'Ushers']]);

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Key', 'test-key'));
});

test('only channels the number owns or administers are offered', function () {
    Http::fake([WAHA.'/api/default/channels' => Http::response([
        ['id' => '1@newsletter', 'name' => 'Ours', 'role' => 'OWNER'],
        ['id' => '2@newsletter', 'name' => 'Helping', 'role' => 'ADMIN'],
        ['id' => '3@newsletter', 'name' => 'Just following', 'role' => 'SUBSCRIBER'],
    ])]);

    expect(array_column(app(Waha::class)->channels(), 'name'))->toBe(['Ours', 'Helping']);
});

test('the pairing code is requested for the team number in international format', function () {
    Http::fake([WAHA.'/api/default/auth/request-code' => Http::response(['code' => 'ABCD-EFGH'])]);

    expect(app(Waha::class)->requestPairingCode())->toBe('ABCD-EFGH');

    Http::assertSent(fn (Request $r) => $r['phoneNumber'] === '254711777992');
});

test('a missing session is reported as null and a new one gets our signed webhook', function () {
    Http::fake([
        WAHA.'/api/sessions/default' => Http::response(['message' => 'not found'], 404),
        WAHA.'/api/sessions' => Http::response(['name' => 'default', 'status' => 'STARTING']),
    ]);

    $waha = app(Waha::class);
    expect($waha->session())->toBeNull();

    $waha->ensureStarted('https://planner.example.com/webhooks/waha');

    Http::assertSent(function (Request $r) {
        $hook = json_decode($r->body(), true)['config']['webhooks'][0] ?? [];

        return $r->url() === WAHA.'/api/sessions'
            && $hook['url'] === 'https://planner.example.com/webhooks/waha'
            && $hook['hmac']['key'] === 'hook-secret'
            && in_array('message.ack', $hook['events'], true);
    });
});

test('wahas errors become friendly messages', function () {
    Http::fake([WAHA.'/*' => Http::response(['message' => 'Unauthorized'], 401)]);
    expect(fn () => app(Waha::class)->session())->toThrow(WahaException::class, 'rejected the API key');

    config(['worship.whatsapp.enabled' => false]);
    expect(fn () => app(Waha::class)->session())->toThrow(WahaException::class, 'not set up');
});

// --- Sending ---------------------------------------------------------------------------

test('groups get the text and the pdf; channels get the text only', function () {
    $this->actingAs(chairman());
    Http::fake([
        WAHA.'/api/sendText' => Http::response(['id' => ['_serialized' => 'true_120363@g.us_MSG1']]),
        WAHA.'/api/sendFile' => Http::response(['id' => 'FILE1']),
    ]);
    $g = group();
    $c = channel();

    Livewire::test('whatsapp-share')
        ->call('open', 'roster', '2026-12')
        ->assertSet('attach', true)
        ->assertSet('selected', [$g->id, $c->id])
        ->call('send')
        ->assertHasNoErrors();

    Http::assertSentCount(3); // text to group, PDF to group, text to channel
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendFile')
        && $r['chatId'] === $g->chat_id
        && $r['file']['mimetype'] === 'application/pdf'
        && str_starts_with(base64_decode($r['file']['data']), '%PDF'));
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendFile') && $r['chatId'] === $c->chat_id);

    $groupLog = WhatsappMessage::where('whatsapp_target_id', $g->id)->first();
    $channelLog = WhatsappMessage::where('whatsapp_target_id', $c->id)->first();
    expect($groupLog->status)->toBe('sent')
        ->and($groupLog->waha_id)->toBe('true_120363@g.us_MSG1')
        ->and($groupLog->attachment)->toEndWith('.pdf')
        ->and($channelLog->attachment)->toBeNull()
        ->and($channelLog->error)->toContain('text only');
});

test('a failed send is logged and reported, others still go out', function () {
    $this->actingAs(chairman());
    $g = group();
    $c = channel();
    Http::fake([
        WAHA.'/api/sendText' => fn (Request $r) => $r['chatId'] === $c->chat_id
            ? Http::response(['message' => 'not admin of channel'], 500)
            : Http::response(['id' => 'OK1']),
    ]);

    Livewire::test('whatsapp-share')
        ->call('open', 'contributions', null, null, 2026)
        ->call('send');

    expect(WhatsappMessage::where('status', 'sent')->count())->toBe(1)
        ->and(WhatsappMessage::where('status', 'failed')->first()->error)->toContain('not admin of channel');
});

test('you must pick at least one group or channel', function () {
    $this->actingAs(chairman());
    group();

    Livewire::test('whatsapp-share')->call('open', 'contributions', null, null, 2026)->set('selected', [])->call('send')->assertHasErrors('selected');
});

test('nobody can share until whatsapp is enabled and a target exists', function () {
    $this->actingAs(chairman());

    Livewire::test('whatsapp-share')->call('open', 'contributions', null, null, 2026)->assertForbidden();
});

// --- Messages & privacy ------------------------------------------------------------------

test('a meeting announcement includes both leaders, time and uniform', function () {
    $praise = Member::factory()->leader()->create(['name' => 'Grace Wanjiru']);
    $worship = Member::factory()->leader()->create(['name' => 'Faith Achieng']);
    $meeting = Ministration::factory()->special('Emergency prayer meeting')->create(['date' => '2026-12-09', 'starts_at' => '18:00', 'ends_at' => '19:30']);
    $meeting->members()->attach($praise->id, ['role' => 'praise_lead']);
    $meeting->members()->attach($worship->id, ['role' => 'worship_lead']);

    $text = app(MessageTemplates::class)->meeting($meeting);

    expect($text)->toContain('Emergency prayer meeting', 'Wednesday, 9 December 2026', '18:00 - 19:30', 'Praise leader: Grace Wanjiru', 'Worship leader: Faith Achieng');
});

test('contribution messages never reveal individual members', function () {
    ContributionRate::create(['year' => 2026, 'monthly_amount' => 500, 'registration_fee' => 1000]);
    $member = Member::factory()->create(['name' => 'Brian Mwangi']);
    $fund = SpecialContribution::create(['title' => 'Uniform fund', 'amount_per_member' => 2000]);
    Payment::create(['member_id' => $member->id, 'type' => 'special', 'year' => 2026, 'special_contribution_id' => $fund->id, 'amount' => 700, 'paid_on' => '2026-11-01', 'method' => 'mpesa']);

    $templates = app(MessageTemplates::class);

    expect($templates->contributionsSummary(2026))->not->toContain('Brian')
        ->and($templates->arising($fund))->not->toContain('Brian')->toContain('2,000 per member');
});

test('arising contributions can never attach the per-member pdf', function () {
    $this->actingAs(chairman());
    group();
    Http::fake([WAHA.'/*' => Http::response(['id' => 'X'])]);
    $fund = SpecialContribution::create(['title' => 'Bereavement', 'amount_per_member' => 1000]);

    Livewire::test('whatsapp-share')
        ->call('open', 'arising', null, $fund->id)
        ->assertSet('canAttach', false)
        ->set('attach', true)
        ->call('send');

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendFile'));
});

// --- Webhook ------------------------------------------------------------------------------

test('delivery receipts update the log, only moving forward', function () {
    $msg = WhatsappMessage::create(['chat_id' => 'x@g.us', 'target_name' => 'Praise Team', 'kind' => 'roster', 'body' => 'hi', 'status' => 'sent', 'waha_id' => 'MSG1']);
    $post = function (int $ack) {
        $body = json_encode(['event' => 'message.ack', 'session' => 'default', 'payload' => ['id' => 'MSG1', 'ack' => $ack]]);

        return $this->call('POST', '/webhooks/waha', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, 'hook-secret'),
        ], $body);
    };

    $post(3)->assertOk();
    expect($msg->fresh()->status)->toBe('read');

    $post(2)->assertOk(); // a late "delivered" must not downgrade "read"
    expect($msg->fresh()->status)->toBe('read');
});

test('webhooks without a valid signature are rejected', function () {
    $this->postJson('/webhooks/waha', ['event' => 'message.ack', 'payload' => ['id' => 'MSG1', 'ack' => 3]], ['X-Webhook-Hmac' => 'forged'])
        ->assertUnauthorized();
});

// --- Admin page ---------------------------------------------------------------------------

test('only admins can open the whatsapp page', function () {
    Http::fake([WAHA.'/api/sessions/default' => Http::response(['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '254711777992@c.us', 'pushName' => 'Praise Team']])]);

    $this->actingAs(User::factory()->create())->get(route('whatsapp'))->assertForbidden();

    $this->actingAs(User::factory()->create(['email' => 'admin@example.com']))
        ->get(route('whatsapp'))->assertOk()->assertSee('Connected')->assertSee('+254 711 777 992');
});

test('an admin can load groups and channels and add them as targets', function () {
    $this->actingAs(User::factory()->create(['email' => 'admin@example.com']));
    Http::fake([
        WAHA.'/api/sessions/default' => Http::response(['name' => 'default', 'status' => 'WORKING']),
        WAHA.'/api/default/groups*' => Http::response([['id' => '111@g.us', 'subject' => 'Praise Team']]),
        WAHA.'/api/default/channels' => Http::response([['id' => '9@newsletter', 'name' => 'Worship Channel', 'role' => 'OWNER']]),
        WAHA.'/api/sendText' => Http::response(['id' => 'T1']),
    ]);

    $page = Livewire::test('pages::whatsapp.index')
        ->call('loadAvailable')
        ->assertSee('Praise Team')
        ->assertSee('Worship Channel')
        ->call('addTarget', '111@g.us')
        ->call('addTarget', '9@newsletter');

    expect(WhatsappTarget::where('chat_id', '111@g.us')->value('is_default'))->toBeTrue()
        ->and(WhatsappTarget::where('chat_id', '9@newsletter')->value('type'))->toBe('channel');

    $page->call('sendTest', WhatsappTarget::where('chat_id', '111@g.us')->value('id'));
    expect(WhatsappMessage::where('kind', 'test')->value('status'))->toBe('sent');
});

test('the admin page explains clearly when WAHA is not running', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->actingAs(User::factory()->create(['email' => 'admin@example.com']))
        ->get(route('whatsapp'))
        ->assertOk()
        ->assertSee('Cannot reach the WhatsApp service')
        ->assertSee('Not set up');
});

// --- One-to-one conversations ------------------------------------------------------------

function signedWebhook($test, array $data)
{
    $body = json_encode($data);

    return $test->call('POST', '/webhooks/waha', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $body, 'hook-secret'),
    ], $body);
}

test('a member reply lands in their conversation, including from a hidden lid id', function () {
    $grace = Member::factory()->create(['name' => 'Grace Wanjiru', 'phone' => '0712345678']);
    $faith = Member::factory()->create(['name' => 'Faith Achieng', 'phone' => '0722000111']);
    Http::fake([WAHA.'/api/default/lids/*' => Http::response(['lid' => '999@lid', 'pn' => '254722000111@c.us'])]);

    signedWebhook($this, ['event' => 'message', 'payload' => ['id' => 'IN1', 'from' => '254712345678@c.us', 'fromMe' => false, 'body' => 'Amen, I will be there']])->assertOk();
    signedWebhook($this, ['event' => 'message', 'payload' => ['id' => 'IN2', 'from' => '999@lid', 'fromMe' => false, 'body' => 'Received, thanks']])->assertOk();
    signedWebhook($this, ['event' => 'message', 'payload' => ['id' => 'IN1', 'from' => '254712345678@c.us', 'fromMe' => false, 'body' => 'Amen, I will be there']]); // duplicate
    signedWebhook($this, ['event' => 'message', 'payload' => ['id' => 'G1', 'from' => '120363@g.us', 'fromMe' => false, 'body' => 'group chatter']]); // group: ignored
    signedWebhook($this, ['event' => 'message', 'payload' => ['id' => 'ME1', 'from' => '254712345678@c.us', 'fromMe' => true, 'body' => 'mine']]); // our own: ignored

    expect(WhatsappMessage::where('direction', 'in')->count())->toBe(2)
        ->and(WhatsappMessage::where('waha_id', 'IN1')->value('member_id'))->toBe($grace->id)
        ->and(WhatsappMessage::where('waha_id', 'IN2')->value('member_id'))->toBe($faith->id)
        ->and(WhatsappMessage::where('waha_id', 'IN2')->value('chat_id'))->toBe('999@lid');
});

test('messaging a member checks the number, shows typing, then sends', function () {
    $member = Member::factory()->create(['phone' => '0712345678']);
    Http::fake([
        WAHA.'/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '254712345678@c.us']),
        WAHA.'/api/default/presence' => Http::response([]),
        WAHA.'/api/sendText' => Http::response(['id' => 'OUT1']),
    ]);

    $log = app(WhatsAppSender::class)->sendToMember($member, 'Hello Grace');

    expect($log->status)->toBe('sent')->and($log->chat_id)->toBe('254712345678@c.us')->and($log->waha_id)->toBe('OUT1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/presence') && $r['presence'] === 'typing');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText') && $r['chatId'] === '254712345678@c.us');
});

test('numbers not on whatsapp and new-contact limits get clear messages', function () {
    $notOn = Member::factory()->create(['phone' => '0700000001']);
    $limited = Member::factory()->create(['phone' => '0700000002']);
    Http::fake([
        WAHA.'/api/contacts/check-exists*' => fn (Request $r) => Http::response(str_contains($r->url(), '254700000001')
            ? ['numberExists' => false]
            : ['numberExists' => true, 'chatId' => '254700000002@c.us']),
        WAHA.'/api/default/presence' => Http::response([]),
        WAHA.'/api/sendText' => Http::response(['message' => 'server returned error 463'], 500),
    ]);
    $sender = app(WhatsAppSender::class);

    expect($sender->sendToMember($notOn, 'hi')->error)->toContain('not on WhatsApp')
        ->and($sender->sendToMember($limited, 'hi')->error)->toContain('temporarily limiting messages to new contacts');
});

test('admins see conversations, opening one marks it read, and can reply', function () {
    $this->actingAs(User::factory()->create(['email' => 'admin@example.com']));
    $member = Member::factory()->create(['name' => 'Grace Wanjiru', 'phone' => '0712345678']);
    WhatsappMessage::create(['direction' => 'in', 'member_id' => $member->id, 'chat_id' => '254712345678@c.us', 'target_name' => 'Grace Wanjiru', 'kind' => 'chat', 'body' => 'Can I swap Sunday?', 'status' => 'received']);
    Http::fake([
        WAHA.'/api/default/presence' => Http::response([]),
        WAHA.'/api/sendText' => Http::response(['id' => 'OUT9']),
    ]);

    $this->get(route('whatsapp.chats'))->assertOk()->assertSee('Grace Wanjiru')->assertSee('Can I swap Sunday?');

    Livewire::test('pages::whatsapp.chats')
        ->call('open', $member->id)
        ->set('reply', 'Yes, swap with Faith')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Yes, swap with Faith');

    expect(WhatsappMessage::where('direction', 'in')->whereNull('read_at')->count())->toBe(0)
        ->and(WhatsappMessage::where('direction', 'out')->value('chat_id'))->toBe('254712345678@c.us'); // reuses their chat
});

test('only admins can open the chats', function () {
    $this->actingAs(User::factory()->create())->get(route('whatsapp.chats'))->assertForbidden();
});

test('an older session missing the incoming-message event is repaired, a correct one left alone', function () {
    $url = 'https://planner.example.com/webhooks/waha';
    Http::fake([
        WAHA.'/api/sessions/default' => Http::sequence()
            ->push(['name' => 'default', 'status' => 'WORKING', 'config' => ['webhooks' => [['url' => $url, 'events' => ['message.ack', 'session.status']]]]])
            ->push(['name' => 'default', 'status' => 'WORKING'])
            ->push(['name' => 'default', 'status' => 'WORKING', 'config' => ['webhooks' => [['url' => $url, 'events' => ['message', 'message.ack', 'session.status']]]]]),
    ]);
    $waha = app(Waha::class);

    expect($waha->syncWebhook($url))->toBeTrue();
    Http::assertSent(function (Request $r) use ($url) {
        $hooks = json_decode($r->body(), true)['config']['webhooks'] ?? [];

        return $r->method() === 'PUT' && count($hooks) === 1 && $hooks[0]['url'] === $url
            && in_array('message', $hooks[0]['events'], true) && $hooks[0]['hmac']['key'] === 'hook-secret';
    });

    expect($waha->syncWebhook($url))->toBeFalse(); // already has every event: no update
});

// --- Chat history & admin users -------------------------------------------------------------

test('opening a chat imports both sides of the conversation once, like the whatsapp inbox', function () {
    $this->actingAs(User::factory()->create(['email' => 'admin@example.com']));
    $member = Member::factory()->create(['name' => 'Isaac', 'phone' => '0759900802']);
    WhatsappMessage::create(['direction' => 'out', 'member_id' => $member->id, 'chat_id' => '254759900802@c.us', 'target_name' => 'Isaac', 'kind' => 'chat', 'body' => 'Sent from the app', 'status' => 'sent', 'waha_id' => 'APP1']);
    Http::fake([WAHA.'/api/default/chats/*' => Http::response([
        ['id' => 'H1', 'fromMe' => false, 'body' => 'Hello team', 'timestamp' => now()->subHour()->timestamp],
        ['id' => 'H2', 'fromMe' => true, 'body' => 'Hi Isaac (typed on the phone)', 'timestamp' => now()->subMinutes(50)->timestamp, 'ack' => 3],
        ['id' => 'DIFFERENT-ID', 'fromMe' => true, 'body' => 'Sent from the app', 'timestamp' => now()->timestamp],
    ])]);

    Livewire::test('pages::whatsapp.chats')->call('open', $member->id)->assertSee('Hello team')->assertSee('typed on the phone');
    Livewire::test('pages::whatsapp.chats')->call('open', $member->id); // again: nothing new

    expect(WhatsappMessage::where('member_id', $member->id)->count())->toBe(3)
        ->and(WhatsappMessage::where('waha_id', 'H2')->value('status'))->toBe('read')
        ->and(WhatsappMessage::where('waha_id', 'H1')->value('direction'))->toBe('in');
});
