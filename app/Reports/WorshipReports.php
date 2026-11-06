<?php

namespace App\Reports;

use App\Enums\MinistryRole;
use App\Enums\ServiceType;
use App\Models\Ministration;
use App\Models\Song;
use App\Models\Uniform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the data behind every PDF / Excel download, so both formats
 * always show exactly the same rows.
 */
class WorshipReports
{
    public static function monthStart(?string $month): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', ($month ?: now()->format('Y-m')).'-01')->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    /** @return Collection<int, Ministration> */
    public function services(CarbonImmutable $month): Collection
    {
        return Ministration::query()
            ->inMonth($month->year, $month->month)
            ->with(['members' => fn ($q) => $q->orderBy('name'), 'uniform', 'songs'])
            ->orderBy('date')->orderBy('starts_at')
            ->get();
    }

    /**
     * Ministers roster rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rosterRows(CarbonImmutable $month): array
    {
        return $this->services($month)->map(function (Ministration $s) {
            $praise = $s->praiseLead();
            $worship = $s->worshipLead();

            return [
                ...$this->serviceMeta($s),
                'praise_lead' => $praise->name ?? 'TBA',
                'praise_part' => (string) ($praise->part ?? ''),
                'worship_lead' => $worship->name ?? 'TBA',
                'worship_part' => (string) ($worship->part ?? ''),
                'backups' => $s->backups()->pluck('name')->implode(', ') ?: '-',
                'uniform' => $s->uniform->name ?? '-',
                'songs' => $s->songs->pluck('title')->implode(', ') ?: '-',
                'song_count' => $s->songs->count(),
            ];
        })->values()->all();
    }

    /**
     * Fields every service row shares (also used for week grouping and badges in PDFs).
     *
     * @return array{date: string, day: string, day_num: string, month_short: string, week: string, service: string, time: string, type: string, type_label: string}
     */
    protected function serviceMeta(Ministration $s): array
    {
        return [
            'date' => $s->date->format('d M Y'),
            'day' => $s->date->format('l'),
            'day_num' => $s->date->format('j'),
            'month_short' => $s->date->format('M'),
            'week' => 'Week of '.$s->date->startOfWeek()->format('j M'),
            'service' => $s->displayTitle(),
            'time' => $s->timeRange(),
            'type' => $s->type->value,
            'type_label' => $s->type === ServiceType::SPECIAL ? 'Special' : ucfirst($s->type->value),
        ];
    }

    /**
     * Headline numbers for the roster PDF.
     *
     * @return array{services: int, specials: int, ministers: int, max_duties: int}
     */
    public function rosterStats(CarbonImmutable $month): array
    {
        $services = $this->services($month);
        $load = $services->flatMap->members->countBy('id');

        return [
            'services' => $services->count(),
            'specials' => $services->where('type', ServiceType::SPECIAL)->count(),
            'ministers' => $load->count(),
            'max_duties' => (int) ($load->max() ?? 0),
        ];
    }

    /**
     * How many times each minister serves / leads in the month.
     *
     * @return array<int, array{name: string, part: string, praise: int, worship: int, backups: int, total: int}>
     */
    public function rosterSummary(CarbonImmutable $month): array
    {
        $rows = [];

        foreach ($this->services($month) as $service) {
            foreach ($service->members as $m) {
                $rows[$m->id] ??= ['name' => $m->name, 'part' => (string) $m->part, 'praise' => 0, 'worship' => 0, 'backups' => 0, 'total' => 0];
                $role = MinistryRole::tryFrom((string) $m->getRelationValue('pivot')?->role);
                $rows[$m->id][match ($role) {
                    MinistryRole::PRAISE_LEAD => 'praise',
                    MinistryRole::WORSHIP_LEAD => 'worship',
                    default => 'backups',
                }]++;
                $rows[$m->id]['total']++;
            }
        }

        usort($rows, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $rows;
    }

    /**
     * Uniform timetable rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function uniformRows(CarbonImmutable $month): array
    {
        return $this->services($month)->map(fn (Ministration $s) => [
            ...$this->serviceMeta($s),
            'uniform' => $s->uniform->name ?? 'Not set',
            'uniform_set' => $s->uniform !== null,
            'top' => $s->uniform->top ?? '-',
            'ladies' => $s->uniform->ladies_bottom ?? '-',
            'men' => $s->uniform->men_bottom ?? '-',
        ])->values()->all();
    }

    /** @return Collection<int, Uniform> Uniforms used in the month, with `uses` = number of services. */
    public function uniformsUsed(CarbonImmutable $month): Collection
    {
        $uses = $this->services($month)->pluck('uniform_id')->filter()->countBy();

        return Uniform::whereIn('id', $uses->keys())->orderBy('name')->get()
            ->each(fn (Uniform $u) => $u->setAttribute('uses', $uses[$u->id] ?? 0));
    }

    /**
     * @param  array{search?: ?string, language?: ?string, category?: ?string}  $filters
     * @return Collection<int, Song>
     */
    public function songs(array $filters = []): Collection
    {
        $search = $filters['search'] ?? null;

        return Song::query()
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('artist', 'like', "%{$search}%")
                ->orWhere('theme', 'like', "%{$search}%")))
            ->when($filters['language'] ?? null, fn ($q, $v) => $q->where('language', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->orderBy('language')->orderBy('title')
            ->get();
    }

    /**
     * @param  Collection<int, Song>  $songs
     * @return array<int, array<string, mixed>>
     */
    public function songRows(Collection $songs): array
    {
        return $songs->map(fn (Song $s) => [
            'title' => $s->title,
            'artist' => (string) $s->artist,
            'language' => $s->language->label(),
            'category' => $s->category->label(),
            'category_key' => $s->category->value,
            'key' => (string) $s->musical_key,
            'tempo' => $s->tempo_bpm ? $s->tempo_bpm.' bpm' : '',
            'time' => (string) $s->time_signature,
            'theme' => (string) $s->theme,
            'lyrics' => $s->lyrics ? 'Yes' : 'No',
        ])->values()->all();
    }
}
