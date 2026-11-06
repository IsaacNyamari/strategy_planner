<?php

namespace App\Roster;

use App\Enums\MinistryRole;
use App\Enums\ServiceType;
use App\Models\Member;
use App\Models\Ministration;
use App\Models\Uniform;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the monthly ministering timetable.
 *
 * Hard rules (never broken):
 *  - a member is never booked into two services/meetings whose times overlap;
 *  - a member is never booked on a date they marked as unavailable;
 *  - inactive members are never booked;
 *  - every service gets a praise leader AND a worship leader, two different
 *    people, each eligible for that session (can_lead_praise / can_lead_worship).
 *
 * Soft rules (fairness): spread duties evenly across the month, share each
 * lead role around, avoid the same person leading back-to-back services,
 * and rotate uniforms.
 */
class RosterGenerator
{
    /** @var array<int, int> member id => duties already given this month */
    protected array $load = [];

    /** @var array<int, int> member id => times led (either session) this month */
    protected array $leads = [];

    /** @var array<string, array<int, int>> role => member id => times led in that role */
    protected array $roleLeads = [];

    /** @var list<int> members who led the previous service (any session) */
    protected array $lastLeadIds = [];

    protected ?int $lastUniformId = null;

    /**
     * Create any missing Sunday/Wednesday/Friday services for the month and
     * staff every service that has nobody assigned yet.
     */
    public function generateMonth(int $year, int $month, ?int $backups = null, bool $reshuffle = false): RosterResult
    {
        $result = new RosterResult;
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        DB::transaction(function () use ($start, $backups, $reshuffle, $result) {
            foreach ($this->serviceDates($start) as [$date, $config]) {
                $ministration = Ministration::query()
                    ->whereDate('date', $date->toDateString())
                    ->where('type', $config['type'])
                    ->first();

                if (! $ministration) {
                    $ministration = Ministration::create([
                        'date' => $date->toDateString(),
                        'type' => $config['type'],
                        'starts_at' => $config['starts_at'],
                        'ends_at' => $config['ends_at'],
                    ]);
                    $result->created++;
                }
            }

            $services = Ministration::query()
                ->inMonth($start->year, $start->month)
                ->with('members')
                ->orderBy('date')->orderBy('starts_at')
                ->get();

            if ($reshuffle) {
                // Special/abrupt meetings keep their hand-picked team.
                foreach ($services->where('type', '!=', ServiceType::SPECIAL) as $service) {
                    $service->members()->detach();
                    $service->setRelation('members', new \Illuminate\Database\Eloquent\Collection);
                }
            }

            $this->primeFairness($services);

            foreach ($services as $service) {
                if ($service->members->isEmpty()) {
                    $this->staff($service, $backups, $result);
                }

                if (! $service->uniform_id) {
                    $this->assignUniform($service);
                }

                $fresh = $service->fresh('members');
                $leadIds = $fresh ? array_values(array_filter([$fresh->praiseLead()?->id, $fresh->worshipLead()?->id])) : [];
                $this->lastLeadIds = $leadIds ?: $this->lastLeadIds;
                $this->lastUniformId = $service->uniform_id ?? $this->lastUniformId;
            }
        });

        return $result;
    }

    /**
     * Staff a single service or abrupt meeting: a praise leader, a worship
     * leader (two different people) and N backups. Only fills what's missing.
     */
    public function staff(Ministration $service, ?int $backups = null, ?RosterResult $result = null): RosterResult
    {
        $result ??= new RosterResult;
        $backups ??= (int) config('worship.backups_per_service', 3);

        if ($this->load === [] && $this->leads === []) {
            $this->primeFairness(Ministration::query()->inMonth($service->date->year, $service->date->month)->with('members')->get());
        }

        $available = $this->availableMembers($service);
        $alreadyIn = $service->members()->pluck('members.id')->all();
        $available = $available->reject(fn (Member $m) => in_array($m->id, $alreadyIn, true));

        $filled = $service->members()->pluck('ministration_member.role')->all();
        $missing = array_values(array_filter(MinistryRole::leads(), fn (MinistryRole $r) => ! in_array($r->value, $filled, true)));

        // Fill the scarcer role first so it isn't starved by the other.
        usort($missing, fn (MinistryRole $a, MinistryRole $b) => $available->filter(fn (Member $m) => $m->canLead($a))->count() <=> $available->filter(fn (Member $m) => $m->canLead($b))->count());

        foreach ($missing as $role) {
            $lead = $this->rankLeads($available->filter(fn (Member $m) => $m->canLead($role)), $role)->first();

            if ($lead) {
                $this->book($service, $lead, $role);
                $available = $available->reject(fn (Member $m) => $m->id === $lead->id);
            } else {
                $result->warnings[] = "{$service->date->format('D j M')}: no available member who can lead {$role->shortLabel()}.";
            }
        }

        $needed = max(0, $backups - $service->members()->wherePivot('role', MinistryRole::BACKUP->value)->count());
        $chosen = $this->rank($available)->take($needed);

        foreach ($chosen as $member) {
            $this->book($service, $member, MinistryRole::BACKUP);
        }

        if ($chosen->count() < $needed) {
            $result->warnings[] = "{$service->date->format('D j M')}: only {$chosen->count()} of {$needed} backups available.";
        }

        $result->staffed++;

        return $result;
    }

    /**
     * Active members who are free (no clash, not unavailable) for this service.
     *
     * @return Collection<int, Member>
     */
    public function availableMembers(Ministration $service): Collection
    {
        $date = $service->date->toDateString();

        $clashing = DB::table('ministration_member')
            ->join('ministrations', 'ministrations.id', '=', 'ministration_member.ministration_id')
            ->whereDate('ministrations.date', $date)
            ->where('ministrations.id', '!=', $service->id)
            ->where('ministrations.starts_at', '<', Ministration::normalizeTime($service->ends_at))
            ->where('ministrations.ends_at', '>', Ministration::normalizeTime($service->starts_at))
            ->pluck('member_id');

        return Member::active()
            ->whereNotIn('id', $clashing)
            ->whereDoesntHave('unavailabilities', fn ($q) => $q->whereDate('date', $date))
            ->get();
    }

    /**
     * Why a member cannot be booked into a service, or null if they can.
     */
    public function conflictFor(Member $member, Ministration $service): ?string
    {
        if (! $member->is_active) {
            return "{$member->name} is inactive.";
        }

        if ($member->isUnavailableOn($service->date)) {
            return "{$member->name} marked themselves unavailable on {$service->date->format('D j M')}.";
        }

        $clash = $member->ministrations()
            ->where('ministrations.id', '!=', $service->id)
            ->overlapping($service->date->toDateString(), $service->starts_at, $service->ends_at)
            ->first();

        if ($clash) {
            return "{$member->name} is already ministering at {$clash->displayTitle()} ({$clash->timeRange()}).";
        }

        return null;
    }

    /**
     * Every Sunday / Wednesday / Friday in the month with its default config.
     *
     * @return list<array{0: CarbonImmutable, 1: array{type: string, starts_at: string, ends_at: string}}>
     */
    public function serviceDates(CarbonImmutable $monthStart): array
    {
        $config = config('worship.services');
        $dates = [];

        for ($day = $monthStart; $day->month === $monthStart->month; $day = $day->addDay()) {
            if (isset($config[$day->dayOfWeekIso])) {
                $dates[] = [$day, $config[$day->dayOfWeekIso]];
            }
        }

        return $dates;
    }

    protected function book(Ministration $service, Member $member, MinistryRole $role): void
    {
        $service->members()->attach($member->id, ['role' => $role->value]);
        $this->load[$member->id] = ($this->load[$member->id] ?? 0) + 1;

        if ($role->isLead()) {
            $this->leads[$member->id] = ($this->leads[$member->id] ?? 0) + 1;
            $this->roleLeads[$role->value][$member->id] = ($this->roleLeads[$role->value][$member->id] ?? 0) + 1;
        }
    }

    /**
     * Backups: fewest duties first; random tie-break so the roster isn't predictable.
     *
     * @param  Collection<int, Member>  $members
     * @return Collection<int, Member>
     */
    protected function rank(Collection $members): Collection
    {
        return $members
            ->map(fn (Member $m) => ['member' => $m, 'score' => [$this->load[$m->id] ?? 0, random_int(0, 1000)]])
            ->sort(fn ($a, $b) => $a['score'] <=> $b['score'])
            ->pluck('member')
            ->values();
    }

    /**
     * Lead candidates: avoid whoever led the previous service, then fewest
     * times in this role, fewest leads overall, fewest duties overall.
     *
     * @param  Collection<int, Member>  $members
     * @return Collection<int, Member>
     */
    protected function rankLeads(Collection $members, MinistryRole $role): Collection
    {
        return $members
            ->map(fn (Member $m) => [
                'member' => $m,
                'score' => [
                    in_array($m->id, $this->lastLeadIds, true) ? 1 : 0,
                    $this->roleLeads[$role->value][$m->id] ?? 0,
                    $this->leads[$m->id] ?? 0,
                    $this->load[$m->id] ?? 0,
                    random_int(0, 1000),
                ],
            ])
            ->sort(fn ($a, $b) => $a['score'] <=> $b['score'])
            ->pluck('member')
            ->values();
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, Ministration> $services */
    protected function primeFairness($services): void
    {
        $this->load = [];
        $this->leads = [];
        $this->roleLeads = [];

        foreach ($services as $service) {
            foreach ($service->members as $member) {
                $this->load[$member->id] = ($this->load[$member->id] ?? 0) + 1;

                $role = MinistryRole::tryFrom((string) $member->getRelationValue('pivot')?->role);

                if ($role?->isLead()) {
                    $this->leads[$member->id] = ($this->leads[$member->id] ?? 0) + 1;
                    $this->roleLeads[$role->value][$member->id] = ($this->roleLeads[$role->value][$member->id] ?? 0) + 1;
                }
            }
        }
    }

    protected function assignUniform(Ministration $service): void
    {
        $uniforms = Uniform::active()->orderBy('id')->get();

        if ($uniforms->isEmpty()) {
            return;
        }

        $usage = Ministration::query()
            ->inMonth($service->date->year, $service->date->month)
            ->whereNotNull('uniform_id')
            ->selectRaw('uniform_id, count(*) as uses')
            ->groupBy('uniform_id')
            ->pluck('uses', 'uniform_id');

        $pick = $uniforms
            ->sortBy(fn (Uniform $u) => [$u->id === $this->lastUniformId ? 1 : 0, $usage[$u->id] ?? 0, $u->id])
            ->first();

        $service->update(['uniform_id' => $pick->id]);
    }

    public static function typeFor(CarbonImmutable $date): ServiceType
    {
        return match ($date->dayOfWeekIso) {
            7 => ServiceType::SUNDAY,
            3 => ServiceType::WEDNESDAY,
            5 => ServiceType::FRIDAY,
            default => ServiceType::SPECIAL,
        };
    }
}
