<?php

use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'worship.timezone' => 'Africa/Nairobi',
        'worship.whatsapp.enabled' => true,
        'worship.whatsapp.url' => 'http://127.0.0.1:3100',
        'worship.whatsapp.api_key' => 'k',
        'services.facebook.page_id' => '1234',
        'services.facebook.page_token' => 'page-token',
        'services.facebook.graph_version' => 'v23.0',
    ]);
    Storage::fake('public');
    $this->group = WhatsappTarget::create(['name' => 'Praise Team', 'chat_id' => '1@g.us', 'type' => 'group', 'is_default' => true]);
});

function duePost(array $attrs = []): ScheduledPost
{
    return ScheduledPost::create([
        'body' => 'Rehearsal moved to 5pm',
        'whatsapp_target_ids' => [WhatsappTarget::first()->id],
        'to_facebook' => true,
        'scheduled_at' => now()->subMinute(),
        ...$attrs,
    ]);
}

test('due posts go to whatsapp and the facebook page, once', function () {
    Http::fake([
        '127.0.0.1:3100/api/sendText' => Http::response(['id' => 'W1']),
        'graph.facebook.com/*' => Http::response(['id' => '1234_99']),
    ]);
    $post = duePost();
    ScheduledPost::create(['body' => 'later', 'whatsapp_target_ids' => [$this->group->id], 'scheduled_at' => now()->addHour()]);

    $this->artisan('posts:publish-due')->assertSuccessful();
    $this->artisan('posts:publish-due'); // second run: nothing left

    expect($post->fresh()->status)->toBe('sent')
        ->and(collect($post->fresh()->results)->pluck('ok')->all())->toBe([true, true]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com/v23.0/1234/feed') && $r['message'] === 'Rehearsal moved to 5pm' && $r['access_token'] === 'page-token');
    expect(ScheduledPost::where('status', 'scheduled')->count())->toBe(1); // the later one is untouched
});

test('a post with an image is sent as a whatsapp image and a facebook photo', function () {
    Http::fake(['127.0.0.1:3100/*' => Http::response(['id' => 'W2']), 'graph.facebook.com/*' => Http::response(['id' => 'P1'])]);
    Storage::disk('public')->put('scheduled-posts/flyer.jpg', 'fake-jpeg-bytes');
    duePost(['image_path' => 'scheduled-posts/flyer.jpg']);

    $this->artisan('posts:publish-due');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendImage') && base64_decode($r['file']['data']) === 'fake-jpeg-bytes' && $r['caption'] === 'Rehearsal moved to 5pm');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1234/photos') && str_contains((string) $r['url'], 'scheduled-posts/flyer.jpg'));
});

test('one failing destination makes the post partial and the error is kept', function () {
    Http::fake(['127.0.0.1:3100/*' => Http::response(['id' => 'W3']), 'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);
    $post = duePost();

    $this->artisan('posts:publish-due');

    expect($post->fresh()->status)->toBe('partial')
        ->and(collect($post->fresh()->results)->firstWhere('name', 'Facebook Page')['error'])->toContain('Invalid OAuth access token');
});

test('weekly posts schedule the next one at the same local time', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 06:00:00', 'UTC')); // 09:00 in Nairobi
    Http::fake(['*' => Http::response(['id' => 'X'])]);
    duePost(['repeat' => 'weekly', 'scheduled_at' => now(), 'to_facebook' => false]);

    $this->artisan('posts:publish-due');

    $next = ScheduledPost::where('status', 'scheduled')->firstOrFail();
    expect($next->localTime()->format('Y-m-d H:i'))->toBe('2026-11-22 09:00')->and($next->repeat)->toBe('weekly');
    Carbon::setTestNow();
});

test('scheduling from the page uses kenya time and rejects past times', function () {
    $this->actingAs(chairman());
    Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'UTC')); // 10:00 in Nairobi

    Livewire::test('pages::posts.index')
        ->call('newPost')
        ->assertSet('targets', [$this->group->id])
        ->set('body', 'Sunday: white & navy uniform')
        ->set('date', '2026-11-10')->set('time', '09:00')
        ->call('save')
        ->assertHasErrors('time')
        ->set('time', '18:30')
        ->set('image', UploadedFile::fake()->image('flyer.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $post = ScheduledPost::firstOrFail();
    expect($post->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-10 15:30') // UTC
        ->and($post->image_path)->toStartWith('scheduled-posts/');
    Carbon::setTestNow();
});

test('a template fills the post text', function () {
    $this->actingAs(chairman());

    Livewire::test('pages::posts.index')->call('newPost')->set('template', 'contributions')->assertSet('body', fn ($b) => str_contains($b, 'Contributions update'));
});

test('the scheduler is hidden until there is somewhere to post', function () {
    config(['services.facebook.page_id' => null]);
    WhatsappTarget::query()->delete();

    $this->actingAs(User::factory()->create())->get(route('posts'))->assertForbidden();
});

test('an audio clip goes to whatsapp as a voice note after the text', function () {
    Http::fake(['127.0.0.1:3100/*' => Http::response(['id' => 'W5']), 'graph.facebook.com/*' => Http::response(['id' => 'F5'])]);
    Storage::disk('public')->put('scheduled-posts/audio/clip.mp3', 'fake-mp3-bytes');
    $post = duePost(['audio_path' => 'scheduled-posts/audio/clip.mp3', 'audio_name' => 'Sunday rehearsal.mp3']);

    $this->artisan('posts:publish-due');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendVoice') && $r['convert'] === true
        && base64_decode($r['file']['data']) === 'fake-mp3-bytes' && $r['file']['filename'] === 'Sunday rehearsal.mp3');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/1234/feed')); // Facebook gets the text
    expect($post->fresh()->status)->toBe('sent');
});

test('if the voice note fails the clip is sent as an audio file instead', function () {
    Http::fake([
        '127.0.0.1:3100/api/sendVoice' => Http::response(['message' => 'conversion failed'], 500),
        '127.0.0.1:3100/*' => Http::response(['id' => 'W6']),
    ]);
    Storage::disk('public')->put('scheduled-posts/audio/clip.wav', 'wav');
    $post = duePost(['audio_path' => 'scheduled-posts/audio/clip.wav', 'to_facebook' => false]);

    $this->artisan('posts:publish-due');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendFile'));
    expect($post->fresh()->status)->toBe('sent');
});

test('audio can be attached on the page and is checked', function () {
    $this->actingAs(chairman());

    Livewire::test('pages::posts.index')
        ->call('newPost')->set('body', 'Listen to this week\'s song')
        ->set('audio', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
        ->call('save')->assertHasErrors('audio')
        ->set('audio', UploadedFile::fake()->create('song.mp3', 3000, 'audio/mpeg'))
        ->call('save')->assertHasNoErrors();

    $post = ScheduledPost::firstOrFail();
    expect($post->audio_path)->toStartWith('scheduled-posts/audio/')->and($post->audio_name)->toBe('song.mp3');
    Storage::disk('public')->assertExists($post->audio_path);
});
