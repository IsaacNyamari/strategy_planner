<?php

namespace App\Ai;

use App\Enums\SongCategory;
use App\Enums\SongLanguage;
use App\Models\Ministration;
use App\Models\Song;
use App\Models\Strategy;
use App\Models\Uniform;
use Carbon\CarbonImmutable;

/**
 * Domain-specific AI features for the praise & worship team.
 */
class WorshipAssistant
{
    protected const SYSTEM = 'You are an assistant for a church praise and worship team in East Africa (Kenya/Tanzania). '
        .'Be practical, modest and culturally appropriate. Never invent facts you are unsure about.';

    public function __construct(protected Gemini $gemini) {}

    public function configured(): bool
    {
        return $this->gemini->configured();
    }

    /**
     * Uniform ideas that differ from what the team already owns.
     *
     * @return array<int, array{name: string, top: string, ladies_bottom: string, men_bottom: string, colors: string, notes: string}>
     */
    public function uniformSuggestions(int $count = 4, ?string $occasion = null): array
    {
        $existing = Uniform::query()->pluck('name')->implode(', ') ?: 'none yet';

        $prompt = "Suggest {$count} distinct, modest uniform sets for the praise & worship team.\n"
            ."Each set has: a top (shirts for men / blouses for ladies, same colour scheme), a ladies' bottom (a dress or skirt) and men's trousers.\n"
            .'Use affordable fabrics a local tailor can make (e.g. cotton, chiffon, crepe, kitenge/ankara accents). '
            ."Keep them coordinated so the team looks unified on stage.\n"
            ."Avoid duplicating sets the team already has: {$existing}.\n"
            .($occasion ? "Occasion / theme: {$occasion}.\n" : '')
            .'For notes, give one short line on when to wear it (e.g. Easter, communion Sunday, midweek).';

        $data = $this->gemini->json($prompt, [
            'type' => 'OBJECT',
            'properties' => [
                'uniforms' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'name' => ['type' => 'STRING'],
                            'top' => ['type' => 'STRING'],
                            'ladies_bottom' => ['type' => 'STRING'],
                            'men_bottom' => ['type' => 'STRING'],
                            'colors' => ['type' => 'STRING'],
                            'notes' => ['type' => 'STRING'],
                        ],
                        'required' => ['name', 'top', 'ladies_bottom', 'men_bottom', 'colors', 'notes'],
                    ],
                ],
            ],
            'required' => ['uniforms'],
        ], self::SYSTEM);

        return collect($this->items($data, 'uniforms'))
            ->filter(fn (array $u) => filled($u['name'] ?? null))
            ->map(fn (array $u) => [
                'name' => (string) $u['name'],
                'top' => (string) ($u['top'] ?? ''),
                'ladies_bottom' => (string) ($u['ladies_bottom'] ?? ''),
                'men_bottom' => (string) ($u['men_bottom'] ?? ''),
                'colors' => (string) ($u['colors'] ?? ''),
                'notes' => (string) ($u['notes'] ?? ''),
            ])
            ->take($count)
            ->values()
            ->all();
    }

    /**
     * Real, well-known songs. Full lyrics only for public-domain hymns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function songSuggestions(SongLanguage $language, ?SongCategory $category = null, ?string $theme = null, int $count = 6): array
    {
        $existing = Song::query()->where('language', $language->value)->pluck('title')->implode('; ') ?: 'none';
        $kind = $category ? $category->label() : 'praise, worship or hymn';
        $lang = $language === SongLanguage::SWAHILI ? 'Kiswahili' : 'English';

        $prompt = "List {$count} REAL, widely sung {$lang} Christian {$kind} songs used by churches in East Africa.\n"
            .'Only include songs you are confident actually exist, with their real writer/artist. Do not invent songs.'."\n"
            .($theme ? "Theme: {$theme}.\n" : '')
            ."Skip songs already in the library: {$existing}.\n"
            ."For each give the commonly used musical key, tempo in BPM and time signature.\n"
            .'COPYRIGHT RULE: set is_public_domain=true only for songs whose words are in the public domain '
            .'(e.g. hymns written before 1929, traditional songs, Tenzi za Rohoni hymns). Provide the full lyrics ONLY for '
            .'public-domain songs. For any copyrighted / contemporary song, set lyrics to an empty string and put just the '
            .'first line in first_line.';

        $data = $this->gemini->json($prompt, [
            'type' => 'OBJECT',
            'properties' => [
                'songs' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'title' => ['type' => 'STRING'],
                            'artist' => ['type' => 'STRING'],
                            'category' => ['type' => 'STRING', 'enum' => array_map(fn ($c) => $c->value, SongCategory::cases())],
                            'musical_key' => ['type' => 'STRING'],
                            'tempo_bpm' => ['type' => 'INTEGER'],
                            'time_signature' => ['type' => 'STRING'],
                            'theme' => ['type' => 'STRING'],
                            'is_public_domain' => ['type' => 'BOOLEAN'],
                            'first_line' => ['type' => 'STRING'],
                            'lyrics' => ['type' => 'STRING'],
                        ],
                        'required' => ['title', 'artist', 'category', 'musical_key', 'tempo_bpm', 'time_signature', 'theme', 'is_public_domain', 'first_line', 'lyrics'],
                    ],
                ],
            ],
            'required' => ['songs'],
        ], self::SYSTEM, 0.4);

        return collect($this->items($data, 'songs'))
            ->filter(fn (array $s) => filled($s['title'] ?? null))
            ->map(function (array $s) use ($language, $category) {
                $publicDomain = (bool) ($s['is_public_domain'] ?? false);

                return [
                    'title' => (string) $s['title'],
                    'artist' => (string) ($s['artist'] ?? ''),
                    'language' => $language->value,
                    'category' => SongCategory::tryFrom((string) ($s['category'] ?? ''))->value ?? ($category !== null ? $category->value : SongCategory::WORSHIP->value),
                    'musical_key' => mb_substr((string) ($s['musical_key'] ?? ''), 0, 10),
                    'tempo_bpm' => isset($s['tempo_bpm']) ? max(30, min(240, (int) $s['tempo_bpm'])) : null,
                    'time_signature' => mb_substr((string) ($s['time_signature'] ?? ''), 0, 5),
                    'theme' => (string) ($s['theme'] ?? ''),
                    'is_public_domain' => $publicDomain,
                    'first_line' => (string) ($s['first_line'] ?? ''),
                    // Enforce the copyright rule server-side too.
                    'lyrics' => $publicDomain ? (string) ($s['lyrics'] ?? '') : '',
                ];
            })
            ->take($count)
            ->values()
            ->all();
    }

    /**
     * Break a strategy into objectives with tasks.
     *
     * @return array<int, array{title: string, description: string, tasks: array<int, array{title: string, quadrant: string, effort_hours: int}>}>
     */
    public function strategyBreakdown(Strategy $strategy): array
    {
        $existing = $strategy->objectives()->pluck('title')->implode('; ') ?: 'none';

        $prompt = "Our praise & worship team has this {$strategy->horizon->label()} strategy:\n"
            ."Title: {$strategy->title}\nDescription: {$strategy->description}\n"
            .($strategy->target_date ? "Target date: {$strategy->target_date->toDateString()}\n" : '')
            ."Existing objectives (do not repeat): {$existing}\n\n"
            .'Propose 3 measurable objectives, each with 3-4 concrete tasks a team member can own. '
            .'For each task choose an Eisenhower quadrant: Do_First, Schedule, Delegate or Eliminate, and an effort in hours.';

        $data = $this->gemini->json($prompt, [
            'type' => 'OBJECT',
            'properties' => [
                'objectives' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'title' => ['type' => 'STRING'],
                            'description' => ['type' => 'STRING'],
                            'tasks' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'title' => ['type' => 'STRING'],
                                        'quadrant' => ['type' => 'STRING', 'enum' => ['Do_First', 'Schedule', 'Delegate', 'Eliminate']],
                                        'effort_hours' => ['type' => 'INTEGER'],
                                    ],
                                    'required' => ['title', 'quadrant', 'effort_hours'],
                                ],
                            ],
                        ],
                        'required' => ['title', 'description', 'tasks'],
                    ],
                ],
            ],
            'required' => ['objectives'],
        ], self::SYSTEM, 0.7);

        return collect($this->items($data, 'objectives'))
            ->filter(fn (array $o) => filled($o['title'] ?? null))
            ->map(fn (array $o) => [
                'title' => (string) $o['title'],
                'description' => (string) ($o['description'] ?? ''),
                'tasks' => collect($this->items($o, 'tasks'))
                    ->filter(fn (array $t) => filled($t['title'] ?? null))
                    ->map(fn (array $t) => [
                        'title' => (string) $t['title'],
                        'quadrant' => in_array($t['quadrant'] ?? '', ['Do_First', 'Schedule', 'Delegate', 'Eliminate'], true) ? $t['quadrant'] : 'Schedule',
                        'effort_hours' => max(1, min(999, (int) ($t['effort_hours'] ?? 1))),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * A friendly WhatsApp-style announcement of the month's roster.
     */
    public function rosterAnnouncement(int $year, int $month, string $language = 'english'): string
    {
        $services = Ministration::query()->inMonth($year, $month)
            ->with(['members', 'uniform'])
            ->orderBy('date')->orderBy('starts_at')
            ->get();

        $lines = $services->map(function (Ministration $s) {
            $praise = $s->praiseLead()->name ?? 'TBA';
            $worship = $s->worshipLead()->name ?? 'TBA';
            $backups = $s->backups()->pluck('name')->implode(', ');
            $uniform = $s->uniform ? "{$s->uniform->name} ({$s->uniform->top}; ladies: {$s->uniform->ladies_bottom}; men: {$s->uniform->men_bottom})" : 'TBA';

            return "- {$s->date->format('D j M')} {$s->timeRange()} | {$s->displayTitle()} | Praise leader: {$praise} | Worship leader: {$worship} | Backups: {$backups} | Uniform: {$uniform}";
        })->implode("\n");

        $monthName = CarbonImmutable::create($year, $month, 1)->format('F Y');
        $lang = $language === 'swahili' ? 'Kiswahili' : 'English';

        return $this->gemini->text(
            "Write a warm, concise WhatsApp announcement in {$lang} for the praise & worship team's ministering timetable for {$monthName}. "
            ."Use the exact data below (do not change names, dates or times). Group by week, use simple emoji, end with a short encouraging scripture reference.\n\n{$lines}",
            self::SYSTEM,
        );
    }

    /**
     * The array items under $key that are themselves arrays (AI JSON is untrusted).
     *
     * @param  array<mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function items(array $data, string $key): array
    {
        $items = $data[$key] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
