<?php

use App\Ai\Gemini;
use App\Ai\GeminiException;
use App\Ai\WorshipAssistant;
use App\Enums\SongLanguage;
use App\Models\Song;
use App\Models\Strategy;
use App\Models\Uniform;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'worship.gemini.api_key' => 'test-key',
        'worship.gemini.models' => ['model-a', 'model-b'],
    ]);
    Cache::flush();
});

function geminiReply(array $payload): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]]];
}

test('gemini falls back to the next model when rate limited', function () {
    Http::fake([
        '*/models/model-a:generateContent' => Http::response(['error' => ['message' => 'quota']], 429),
        '*/models/model-b:generateContent' => Http::response(geminiReply(['ok' => true])),
    ]);

    expect(app(Gemini::class)->json('hi', ['type' => 'OBJECT']))->toBe(['ok' => true]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'model-b') && $r->hasHeader('x-goog-api-key', 'test-key'));
});

test('gemini reports a friendly error when every model is rate limited', function () {
    Http::fake(['*' => Http::response([], 429)]);

    app(Gemini::class)->json('hi', ['type' => 'OBJECT']);
})->throws(GeminiException::class, 'rate-limited');

test('gemini is reported as unconfigured without a key', function () {
    config(['worship.gemini.api_key' => null]);

    expect(app(WorshipAssistant::class)->configured())->toBeFalse();
});

test('uniform suggestions load on the page and can be validated and saved', function () {
    $this->actingAs(chairman());

    Http::fake(['*' => Http::response(geminiReply(['uniforms' => [
        ['name' => 'Royal blue & gold', 'top' => 'Royal blue shirts / blouses', 'ladies_bottom' => 'Gold chiffon skirt', 'men_bottom' => 'Black trousers', 'colors' => 'Royal blue, gold', 'notes' => 'Communion Sunday'],
        ['name' => 'Kitenge Friday', 'top' => 'Kitenge-print tops', 'ladies_bottom' => 'Black A-line dress', 'men_bottom' => 'Khaki trousers', 'colors' => 'Mixed', 'notes' => 'Midweek'],
    ]]))]);

    $page = Livewire::test('pages::uniforms.index')
        ->call('generateSuggestions')
        ->assertSet('suggestionsLoaded', true)
        ->assertSet('suggestions.0.name', 'Royal blue & gold');

    // Blank a required field: validation must stop the save.
    $page->set('suggestions.0.men_bottom', '')
        ->call('acceptSuggestion', 0)
        ->assertHasErrors('suggestions.0.men_bottom');
    expect(Uniform::count())->toBe(0);

    $page->set('suggestions.0.men_bottom', 'Navy trousers')
        ->call('acceptSuggestion', 0)
        ->assertHasNoErrors();

    $uniform = Uniform::firstOrFail();
    expect($uniform->men_bottom)->toBe('Navy trousers')
        ->and($uniform->source)->toBe('ai')
        ->and($page->get('suggestions'))->toHaveCount(1);
});

test('uniforms page shows a helpful message when ai fails', function () {
    $this->actingAs(chairman());
    Http::fake(['*' => Http::response([], 429)]);

    Livewire::test('pages::uniforms.index')
        ->call('generateSuggestions')
        ->assertSee('rate-limited');
});

test('ai lyrics are only kept for public-domain songs', function () {
    Http::fake(['*' => Http::response(geminiReply(['songs' => [
        ['title' => 'Amazing Grace', 'artist' => 'John Newton', 'category' => 'hymn', 'musical_key' => 'G', 'tempo_bpm' => 72, 'time_signature' => '3/4', 'theme' => 'Grace', 'is_public_domain' => true, 'first_line' => 'Amazing grace', 'lyrics' => 'Amazing grace, how sweet the sound'],
        ['title' => 'Modern Song', 'artist' => 'Someone', 'category' => 'worship', 'musical_key' => 'D', 'tempo_bpm' => 70, 'time_signature' => '4/4', 'theme' => 'Love', 'is_public_domain' => false, 'first_line' => 'Line one', 'lyrics' => 'copyrighted words'],
    ]]))]);

    $songs = app(WorshipAssistant::class)->songSuggestions(SongLanguage::ENGLISH);

    expect($songs[0]['lyrics'])->toContain('Amazing grace')
        ->and($songs[1]['lyrics'])->toBe('');
});

test('song suggestions can be saved to the library', function () {
    $this->actingAs(chairman());
    Http::fake(['*' => Http::response(geminiReply(['songs' => [
        ['title' => 'Yesu Ni Bwana', 'artist' => 'Traditional', 'category' => 'praise', 'musical_key' => 'C', 'tempo_bpm' => 110, 'time_signature' => '4/4', 'theme' => 'Lordship', 'is_public_domain' => true, 'first_line' => 'Yesu ni Bwana', 'lyrics' => 'Yesu ni Bwana'],
    ]]))]);

    Livewire::test('pages::songs.index')
        ->set('aiLanguage', 'swahili')
        ->call('suggest')
        ->call('acceptSuggestion', 0)
        ->assertHasNoErrors();

    $song = Song::firstOrFail();
    expect($song->language)->toBe(SongLanguage::SWAHILI)->and($song->source)->toBe('ai');
});

test('ai strategy breakdown adds objectives and tasks', function () {
    $this->actingAs(chairman());
    $strategy = Strategy::factory()->create();
    Http::fake(['*' => Http::response(geminiReply(['objectives' => [
        ['title' => 'Train two new worship leaders', 'description' => 'Mentorship', 'tasks' => [
            ['title' => 'Pick candidates', 'quadrant' => 'Do_First', 'effort_hours' => 2],
            ['title' => 'Weekly coaching', 'quadrant' => 'Schedule', 'effort_hours' => 8],
        ]],
    ]]))]);

    Livewire::test('pages::strategies.show', ['strategy' => $strategy])
        ->call('generateBreakdown')
        ->call('applyBreakdown');

    expect($strategy->objectives()->count())->toBe(1)
        ->and($strategy->tasks()->count())->toBe(2);
});

test('opening the uniforms and songs pages never calls the ai', function () {
    $this->actingAs(chairman());
    Http::fake();

    $this->get(route('uniforms'))->assertOk()->assertSee('Generate ideas');
    $this->get(route('songs'))->assertOk();
    Livewire::test('pages::uniforms.index')->assertSet('suggestionsLoaded', false);
    Livewire::test('pages::songs.index')->assertSet('suggestions', []);

    Http::assertNothingSent();
});

test('uniform ideas are generated only on click, and "new ideas" asks again', function () {
    $this->actingAs(chairman());
    Http::fake(['*' => Http::response(geminiReply(['uniforms' => [
        ['name' => 'Ivory & teal', 'top' => 'Ivory tops', 'ladies_bottom' => 'Teal skirt', 'men_bottom' => 'Teal trousers', 'colors' => 'Ivory, teal', 'notes' => 'Easter'],
    ]]))]);

    $page = Livewire::test('pages::uniforms.index');
    Http::assertSentCount(0);

    $page->call('generateSuggestions')->assertSet('suggestionsLoaded', true);
    Http::assertSentCount(1);

    $page->call('generateSuggestions'); // "New ideas" bypasses the cache
    Http::assertSentCount(2);
});
