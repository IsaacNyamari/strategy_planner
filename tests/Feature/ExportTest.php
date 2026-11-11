<?php

use App\Enums\SongLanguage;
use App\Models\Member;
use App\Models\Ministration;
use App\Models\Song;
use App\Models\Uniform;
use App\Reports\WorshipReports;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

function seedMonth(): Ministration
{
    $uniform = Uniform::factory()->create(['name' => 'Maroon & black', 'top' => 'Maroon shirts', 'ladies_bottom' => 'Black skirt', 'men_bottom' => 'Black trousers']);
    $lead = Member::factory()->leader()->create(['name' => 'Grace Wanjiru']);
    $worship = Member::factory()->worshipLeader()->create(['name' => 'Faith Achieng']);
    $backup = Member::factory()->backupOnly()->create(['name' => 'Brian Mwangi']);
    $service = Ministration::factory()->create(['date' => '2026-12-06', 'uniform_id' => $uniform->id]);
    $service->members()->attach($lead->id, ['role' => 'praise_lead']);
    $service->members()->attach($worship->id, ['role' => 'worship_lead']);
    $service->members()->attach($backup->id, ['role' => 'backup']);
    $song = Song::factory()->create(['title' => 'Amazing Grace', 'language' => SongLanguage::ENGLISH, 'lyrics' => 'Amazing grace, how sweet the sound']);
    $service->songs()->attach($song->id, ['position' => 1]);

    return $service;
}

test('guests cannot download exports', function (string $route) {
    $this->get(route($route, ['format' => 'pdf']))->assertRedirect(route('login'));
})->with(['exports.roster', 'exports.uniforms', 'exports.songs']);

test('every export downloads as a real pdf and excel file', function (string $route, string $format) {
    $this->actingAs(chairman());
    seedMonth();

    $response = $this->get(route($route, ['format' => $format, 'month' => '2026-12']));
    $response->assertOk();

    $disposition = $response->headers->get('content-disposition');
    expect($disposition)->toContain('attachment')->toContain('.'.$format);

    $body = $response->baseResponse instanceof BinaryFileResponse
        ? file_get_contents($response->baseResponse->getFile()->getPathname())
        : $response->getContent();

    // PDF magic bytes vs. XLSX (a zip archive).
    expect(substr($body, 0, $format === 'pdf' ? 4 : 2))->toBe($format === 'pdf' ? '%PDF' : 'PK');
})->with(['exports.roster', 'exports.uniforms', 'exports.songs'])->with(['pdf', 'xlsx']);

test('unsupported formats are rejected', function () {
    $this->actingAs(chairman());

    $this->get('/exports/roster.docx')->assertNotFound();
});

test('roster export rows show both leaders, backups, uniform and songs', function () {
    seedMonth();
    $reports = app(WorshipReports::class);
    $month = CarbonImmutable::create(2026, 12, 1);

    $row = $reports->rosterRows($month)[0];
    expect($row['praise_lead'])->toBe('Grace Wanjiru')
        ->and($row['worship_lead'])->toBe('Faith Achieng')
        ->and($row['backups'])->toBe('Brian Mwangi')
        ->and($row['uniform'])->toBe('Maroon & black')
        ->and($row['songs'])->toBe('Amazing Grace')
        ->and($row['day'])->toBe('Sunday');

    $summary = collect($reports->rosterSummary($month))->keyBy('name');
    expect($summary['Grace Wanjiru']['praise'])->toBe(1)
        ->and($summary['Faith Achieng']['worship'])->toBe(1)
        ->and($summary['Brian Mwangi']['backups'])->toBe(1);

    $uniform = $reports->uniformRows($month)[0];
    expect($uniform['ladies'])->toBe('Black skirt')->and($uniform['men'])->toBe('Black trousers');
});

test('songs export follows the page filters', function () {
    Song::factory()->create(['title' => 'Yesu Ni Bwana', 'language' => SongLanguage::SWAHILI]);
    Song::factory()->create(['title' => 'Way Maker', 'language' => SongLanguage::ENGLISH]);

    $titles = collect(app(WorshipReports::class)->songs(['language' => 'swahili']))->pluck('title');

    expect($titles->all())->toBe(['Yesu Ni Bwana']);
});

test('a month with no services still produces a pdf', function () {
    $this->actingAs(chairman());

    $this->get(route('exports.roster', ['format' => 'pdf', 'month' => '2030-01']))->assertOk();
});
