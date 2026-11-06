<?php

use App\Ai\GeminiException;
use App\Ai\WorshipAssistant;
use App\Enums\MinistryRole;
use App\Enums\ServiceType;
use App\Models\Member;
use App\Models\Ministration;
use App\Models\Song;
use App\Models\Uniform;
use App\Roster\RosterGenerator;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Ministering roster')] class extends Component {
    #[Url]
    public ?string $month = null; // Y-m

    public int $backups = 3;
    public bool $reshuffle = false;

    // Special / abrupt meeting form
    public ?int $editingServiceId = null;
    public string $meetingTitle = '';
    public ?string $meetingDate = null;
    public string $meetingStart = '18:00';
    public string $meetingEnd = '19:30';
    public ?int $meetingUniformId = null;
    public bool $meetingAutoAssign = true;
    public string $meetingNotes = '';

    // Manage service modal
    public ?int $managingId = null;
    public ?int $addMemberId = null;
    public string $addRole = 'backup';
    public ?int $addSongId = null;

    // AI announcement
    public string $announcement = '';
    public string $announcementLanguage = 'english';

    public function mount(): void
    {
        $this->month ??= now()->format('Y-m');
        $this->backups = (int) config('worship.backups_per_service', 3);
    }

    #[Computed]
    public function start(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = $this->start->addMonths($by)->format('Y-m');
        unset($this->start, $this->services);
    }

    #[Computed]
    public function services()
    {
        return Ministration::query()
            ->inMonth($this->start->year, $this->start->month)
            ->with(['members' => fn ($q) => $q->orderBy('name'), 'uniform'])
            ->withCount('songs')
            ->orderBy('date')->orderBy('starts_at')
            ->get();
    }

    #[Computed]
    public function weeks()
    {
        return $this->services->groupBy(fn (Ministration $s) => $s->date->startOfWeek()->format('Y-m-d'));
    }

    #[Computed]
    public function uniforms()
    {
        return Uniform::active()->orderBy('name')->get();
    }

    #[Computed]
    public function stats(): array
    {
        $load = $this->services->flatMap->members->countBy('id');

        return [
            'services' => $this->services->count(),
            'unstaffed' => $this->services->filter(fn ($s) => $s->members->isEmpty())->count(),
            'specials' => $this->services->where('type', ServiceType::SPECIAL)->count(),
            'ministers' => $load->count(),
            'maxLoad' => $load->max() ?? 0,
        ];
    }

    #[Computed]
    public function managing(): ?Ministration
    {
        return $this->managingId
            ? Ministration::with(['members' => fn ($q) => $q->orderBy('name'), 'uniform', 'songs'])->find($this->managingId)
            : null;
    }

    /** Members who could be added to the managed service, with any conflict reason. */
    #[Computed]
    public function candidates()
    {
        if (! $this->managing) {
            return collect();
        }

        $generator = app(RosterGenerator::class);
        $inService = $this->managing->members->pluck('id');

        return Member::active()->orderBy('name')->get()
            ->reject(fn (Member $m) => $inService->contains($m->id))
            ->map(fn (Member $m) => ['member' => $m, 'conflict' => $generator->conflictFor($m, $this->managing)]);
    }

    #[Computed]
    public function songOptions()
    {
        return Song::orderBy('title')->get(['id', 'title', 'language', 'musical_key']);
    }

    // --- Generation ---------------------------------------------------

    public function generate(): void
    {
        $this->validate(['backups' => ['required', 'integer', 'min:0', 'max:20']]);

        $result = app(RosterGenerator::class)->generateMonth($this->start->year, $this->start->month, $this->backups, $this->reshuffle);

        $this->reshuffle = false;
        Flux::modal('generate')->close();
        Flux::toast(
            variant: $result->warnings ? 'warning' : 'success',
            heading: 'Roster generated',
            text: $result->summary().($result->warnings ? ' '.implode(' ', array_slice($result->warnings, 0, 3)) : ''),
        );
        $this->refreshAll();
    }

    // --- Special / abrupt meetings -----------------------------------

    public function newMeeting(?string $date = null): void
    {
        $this->reset('editingServiceId', 'meetingTitle', 'meetingUniformId', 'meetingNotes');
        $this->meetingDate = $date ?? today()->toDateString();
        $this->meetingStart = '18:00';
        $this->meetingEnd = '19:30';
        $this->meetingAutoAssign = true;
        $this->resetValidation();
        Flux::modal('meeting-form')->show();
    }

    public function editDetails(int $id): void
    {
        $s = Ministration::findOrFail($id);
        $this->editingServiceId = $s->id;
        $this->meetingTitle = (string) $s->title;
        $this->meetingDate = $s->date->toDateString();
        $this->meetingStart = substr($s->starts_at, 0, 5);
        $this->meetingEnd = substr($s->ends_at, 0, 5);
        $this->meetingUniformId = $s->uniform_id;
        $this->meetingNotes = (string) $s->notes;
        $this->meetingAutoAssign = false;
        $this->resetValidation();
        Flux::modal('meeting-form')->show();
    }

    public function saveMeeting(): void
    {
        $this->validate([
            'meetingTitle' => [$this->editingServiceId ? 'nullable' : 'required', 'string', 'max:255'],
            'meetingDate' => ['required', 'date'],
            'meetingStart' => ['required', 'date_format:H:i'],
            'meetingEnd' => ['required', 'date_format:H:i', 'after:meetingStart'],
            'meetingUniformId' => ['nullable', 'exists:uniforms,id'],
            'meetingNotes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['meetingTitle' => 'title', 'meetingDate' => 'date', 'meetingStart' => 'start time', 'meetingEnd' => 'end time']);

        $data = [
            'title' => $this->meetingTitle ?: null,
            'date' => $this->meetingDate,
            'starts_at' => $this->meetingStart,
            'ends_at' => $this->meetingEnd,
            'uniform_id' => $this->meetingUniformId,
            'notes' => $this->meetingNotes ?: null,
        ];

        $generator = app(RosterGenerator::class);

        if ($this->editingServiceId) {
            $service = Ministration::findOrFail($this->editingServiceId);
            $service->update($data);

            // Re-check the team against the new time; drop anyone who now clashes.
            $dropped = $service->members()->get()->filter(fn (Member $m) => $generator->conflictFor($m, $service->fresh()))->each(fn ($m) => $service->members()->detach($m->id));
            Flux::toast(variant: $dropped->isNotEmpty() ? 'warning' : 'success', text: 'Details saved.'.($dropped->isNotEmpty() ? ' Removed due to clashes: '.$dropped->pluck('name')->implode(', ') : ''));
        } else {
            $service = Ministration::create([...$data, 'type' => ServiceType::SPECIAL]);

            $overlaps = Ministration::query()->whereKeyNot($service->id)->overlapping($service->date->toDateString(), $service->starts_at, $service->ends_at)->count();
            $message = 'Special meeting added.';

            if ($this->meetingAutoAssign) {
                $result = $generator->staff($service, $this->backups);
                $message .= ' Team auto-assigned'.($result->warnings ? ' ('.implode(' ', $result->warnings).')' : '').'.';
            }

            if ($overlaps) {
                $message .= " It overlaps {$overlaps} other service(s); anyone ministering there was skipped.";
            }

            Flux::toast(variant: $overlaps ? 'warning' : 'success', text: $message);
            $this->month = $service->date->format('Y-m');
        }

        Flux::modal('meeting-form')->close();
        $this->refreshAll();
    }

    public function deleteService(int $id): void
    {
        Ministration::findOrFail($id)->delete();
        Flux::modal('manage')->close();
        Flux::toast(text: 'Service removed from the roster.');
        $this->refreshAll();
    }

    // --- Manage a service's team / songs / uniform ---------------------

    public function manage(int $id): void
    {
        $this->managingId = $id;
        $this->reset('addMemberId', 'addSongId');
        $this->addRole = 'backup';
        unset($this->managing, $this->candidates);
        Flux::modal('manage')->show();
    }

    public function addMember(): void
    {
        $this->validate([
            'addMemberId' => ['required', 'exists:members,id'],
            'addRole' => ['required', \Illuminate\Validation\Rule::enum(MinistryRole::class)],
        ], attributes: ['addMemberId' => 'member']);

        $service = $this->managing;
        $member = Member::findOrFail($this->addMemberId);
        $role = MinistryRole::from($this->addRole);

        if ($conflict = app(RosterGenerator::class)->conflictFor($member, $service)) {
            $this->addError('addMemberId', $conflict);

            return;
        }

        if ($role->isLead()) {
            if (! $member->canLead($role)) {
                $this->addError('addMemberId', "{$member->name} is not marked as able to lead {$role->shortLabel()}.");

                return;
            }
            // Only one person per lead role: the current holder becomes a backup.
            $this->demoteRole($service, $role);
        }

        $service->members()->attach($member->id, ['role' => $role->value]);
        $this->reset('addMemberId');
        $this->addRole = 'backup';
        $this->refreshAll();
    }

    public function removeMember(int $memberId): void
    {
        $this->managing?->members()->detach($memberId);
        $this->refreshAll();
    }

    /** Give a team member the praise or worship lead (the previous holder becomes a backup). */
    public function setRole(int $memberId, string $role): void
    {
        $service = $this->managing;
        $member = $service?->members->firstWhere('id', $memberId);
        $role = MinistryRole::tryFrom($role);

        if (! $member || ! $role) {
            return;
        }

        if ($role->isLead() && ! $member->canLead($role)) {
            Flux::toast(variant: 'warning', text: "{$member->name} is not marked as able to lead {$role->shortLabel()}. Edit them on the Members page first.");

            return;
        }

        if ($role->isLead()) {
            $this->demoteRole($service, $role);
        }

        $service->members()->updateExistingPivot($memberId, ['role' => $role->value]);
        $this->refreshAll();
    }

    protected function demoteRole(Ministration $service, MinistryRole $role): void
    {
        $service->members()->newPivotStatement()
            ->where('ministration_id', $service->id)
            ->where('role', $role->value)
            ->update(['role' => MinistryRole::BACKUP->value]);
    }

    public function autoFill(): void
    {
        $result = app(RosterGenerator::class)->staff($this->managing, $this->backups);
        Flux::toast(variant: $result->warnings ? 'warning' : 'success', text: $result->warnings ? implode(' ', $result->warnings) : 'Team filled.');
        $this->refreshAll();
    }

    public function setUniform(?int $uniformId): void
    {
        $this->managing?->update(['uniform_id' => $uniformId ?: null]);
        $this->refreshAll();
    }

    public function addSong(): void
    {
        $this->validate(['addSongId' => ['required', 'exists:songs,id']], attributes: ['addSongId' => 'song']);
        $service = $this->managing;

        if (! $service->songs->contains('id', $this->addSongId)) {
            $service->songs()->attach($this->addSongId, ['position' => ($service->songs->max('pivot.position') ?? 0) + 1]);
        }

        $this->reset('addSongId');
        $this->refreshAll();
    }

    public function removeSong(int $songId): void
    {
        $this->managing?->songs()->detach($songId);
        $this->refreshAll();
    }

    // --- AI ------------------------------------------------------------

    public function draftAnnouncement(): void
    {
        $this->announcement = '';

        try {
            $this->announcement = app(WorshipAssistant::class)->rosterAnnouncement($this->start->year, $this->start->month, $this->announcementLanguage);
        } catch (GeminiException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    protected function refreshAll(): void
    {
        unset($this->start, $this->services, $this->weeks, $this->stats, $this->managing, $this->candidates);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Ministering roster</flux:heading>
            <flux:subheading>Sundays, Wednesday &amp; Friday midweek services, plus any special meetings.</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:dropdown>
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down">Download</flux:button>
                <flux:menu>
                    <flux:menu.heading>{{ $this->start->format('F Y') }} roster</flux:menu.heading>
                    <flux:menu.item icon="document-text" :href="route('exports.roster', ['format' => 'pdf', 'month' => $this->start->format('Y-m')])">PDF</flux:menu.item>
                    <flux:menu.item icon="table-cells" :href="route('exports.roster', ['format' => 'xlsx', 'month' => $this->start->format('Y-m')])">Excel</flux:menu.item>
                    @can('send-whatsapp')
                        <flux:menu.separator />
                        <flux:menu.item icon="chat-bubble-left-right" x-on:click="$dispatch('whatsapp-share', { type: 'roster', month: '{{ $this->start->format('Y-m') }}' })">Send to WhatsApp</flux:menu.item>
                    @endcan
                </flux:menu>
            </flux:dropdown>
            <flux:button icon="sparkles" wire:click="$set('announcement', '')" x-on:click="$flux.modal('announcement').show()">AI announcement</flux:button>
            <flux:button icon="bolt" wire:click="newMeeting">Special meeting</flux:button>
            <flux:modal.trigger name="generate">
                <flux:button variant="primary" icon="arrow-path">Generate month</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    {{-- Month navigation + stats --}}
    <flux:card class="flex flex-wrap items-center justify-between gap-4 p-4!">
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftMonth(-1)" aria-label="Previous month" />
            <flux:heading size="lg" class="w-40 text-center">{{ $this->start->format('F Y') }}</flux:heading>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftMonth(1)" aria-label="Next month" />
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <flux:badge>{{ $this->stats['services'] }} services</flux:badge>
            @if ($this->stats['specials'])<flux:badge color="rose">{{ $this->stats['specials'] }} special</flux:badge>@endif
            <flux:badge color="blue">{{ $this->stats['ministers'] }} ministers used</flux:badge>
            @if ($this->stats['maxLoad'])<flux:badge color="zinc">max {{ $this->stats['maxLoad'] }} duties / person</flux:badge>@endif
            @if ($this->stats['unstaffed'])<flux:badge color="amber" icon="exclamation-triangle">{{ $this->stats['unstaffed'] }} need a team</flux:badge>@endif
        </div>
    </flux:card>

    @if ($this->services->isEmpty())
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <flux:icon.calendar-days class="size-10 text-zinc-300 dark:text-zinc-600" />
            <flux:heading>No roster for {{ $this->start->format('F') }} yet</flux:heading>
            <flux:text>Generate it to create every Sunday, Wednesday and Friday service and assign ministers fairly with no clashes.</flux:text>
            <flux:modal.trigger name="generate">
                <flux:button size="sm" variant="primary" icon="arrow-path">Generate {{ $this->start->format('F') }}</flux:button>
            </flux:modal.trigger>
        </flux:card>
    @else
        @foreach ($this->weeks as $weekStart => $services)
            <div wire:key="week-{{ $weekStart }}">
                <flux:text size="sm" class="mb-2 font-medium uppercase tracking-wide">Week of {{ \Carbon\CarbonImmutable::parse($weekStart)->format('j M') }}</flux:text>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($services as $service)
                        <flux:card wire:key="service-{{ $service->id }}" @class(['flex flex-col gap-3 p-4!', 'border-rose-300! dark:border-rose-500/50!' => $service->type === \App\Enums\ServiceType::SPECIAL])>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <flux:heading>{{ $service->date->format('D j M') }}</flux:heading>
                                        <flux:badge size="sm" :color="$service->type->color()">{{ $service->type === \App\Enums\ServiceType::SPECIAL ? 'Special' : ucfirst($service->type->value) }}</flux:badge>
                                    </div>
                                    <flux:text size="sm" class="truncate">{{ $service->title ?: $service->type->label() }} · {{ $service->timeRange() }}</flux:text>
                                </div>
                                <flux:button size="sm" variant="ghost" icon="adjustments-horizontal" wire:click="manage({{ $service->id }})" aria-label="Manage service" />
                            </div>

                            @if ($service->members->isEmpty())
                                <div class="flex items-center justify-between rounded-lg border border-dashed border-amber-300 px-3 py-2 dark:border-amber-500/40">
                                    <flux:text size="sm">No team yet</flux:text>
                                    <flux:button size="xs" wire:click="manage({{ $service->id }})">Assign</flux:button>
                                </div>
                            @else
                                <div class="space-y-1.5">
                                    @foreach (\App\Enums\MinistryRole::leads() as $role)
                                        @php $leader = $service->leadFor($role); @endphp
                                        <div class="flex items-center gap-2 text-sm">
                                            <flux:badge size="sm" :color="$role->color()" class="w-16 justify-center">{{ $role->shortLabel() }}</flux:badge>
                                            @if ($leader)
                                                <span class="font-medium text-zinc-800 dark:text-white">{{ $leader->name }}</span>
                                            @else
                                                <span class="text-amber-600 dark:text-amber-400">Not assigned</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    <div class="text-sm text-zinc-600 dark:text-zinc-300">
                                        <span class="text-xs text-zinc-400">Backups:</span> {{ $service->backups()->pluck('name')->implode(', ') ?: 'none' }}
                                    </div>
                                </div>
                            @endif

                            <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-zinc-100 pt-2 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <span class="flex items-center gap-1"><flux:icon.swatch variant="micro" /> {{ $service->uniform?->name ?? 'No uniform' }}</span>
                                <span class="flex items-center gap-1"><flux:icon.musical-note variant="micro" /> {{ $service->songs_count }} {{ Str::plural('song', $service->songs_count) }}</span>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

    {{-- Generate modal --}}
    <flux:modal name="generate" class="md:w-md">
        <form wire:submit="generate" class="space-y-5">
            <div>
                <flux:heading size="lg">Generate {{ $this->start->format('F Y') }}</flux:heading>
                <flux:text class="mt-1">Creates any missing Sunday, Wednesday and Friday services and assigns a praise leader, a worship leader (two different people) plus backups. Nobody is booked twice at the same time or on a day they marked unavailable, and duties are spread evenly. Uniforms rotate automatically.</flux:text>
            </div>
            <flux:input wire:model="backups" type="number" min="0" max="20" label="Backups per service" />
            <flux:checkbox wire:model="reshuffle" label="Reshuffle existing assignments" description="Clears and re-assigns the regular services this month. Special meetings keep their team." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Generate</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Special meeting / edit details modal --}}
    <flux:modal name="meeting-form" class="md:w-lg">
        <form wire:submit="saveMeeting" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingServiceId ? 'Edit service details' : 'Special / abrupt meeting' }}</flux:heading>
                @unless ($editingServiceId)
                    <flux:text class="mt-1">For crusades, funerals, weddings, overnight prayers or any short-notice meeting.</flux:text>
                @endunless
            </div>
            <flux:input wire:model="meetingTitle" label="Title" placeholder="e.g. Emergency prayer meeting" />
            <div class="grid grid-cols-3 gap-3">
                <flux:input wire:model="meetingDate" type="date" label="Date" />
                <flux:input wire:model="meetingStart" type="time" label="Starts" />
                <flux:input wire:model="meetingEnd" type="time" label="Ends" />
            </div>
            <flux:select wire:model="meetingUniformId" label="Uniform">
                <flux:select.option value="">No uniform / casual</flux:select.option>
                @foreach ($this->uniforms as $u)
                    <flux:select.option :value="$u->id">{{ $u->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="meetingNotes" label="Notes" rows="2" />
            @unless ($editingServiceId)
                <flux:checkbox wire:model="meetingAutoAssign" label="Auto-assign a team now" description="Picks free members only: anyone ministering at an overlapping service or unavailable that day is skipped." />
            @endunless
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Manage service modal --}}
    <flux:modal name="manage" class="w-full md:w-2xl">
        @if ($s = $this->managing)
            <div class="space-y-6" x-data="{ tab: 'team' }">
                <div class="flex items-start justify-between gap-4 pe-8">
                    <div>
                        <flux:heading size="lg">{{ $s->displayTitle() }}</flux:heading>
                        <flux:text>{{ $s->date->format('l, j F Y') }} · {{ $s->timeRange() }}</flux:text>
                    </div>
                    <flux:button size="sm" icon="pencil-square" wire:click="editDetails({{ $s->id }})">Details</flux:button>
                </div>

                <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-white/5">
                    @foreach (['team' => 'Team', 'uniform' => 'Uniform', 'songs' => 'Setlist'] as $key => $label)
                        <button type="button" x-on:click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-white shadow-sm dark:bg-zinc-700 text-zinc-900 dark:text-white' : 'text-zinc-500'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium">{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Team --}}
                <div x-show="tab === 'team'" class="space-y-4">
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @forelse ($s->members as $m)
                            <div class="flex items-center justify-between gap-2 py-2" wire:key="team-{{ $m->id }}">
                                <div class="flex items-center gap-2">
                                    <flux:avatar size="xs" :name="$m->name" :initials="$m->initials()" color="auto" />
                                    <span class="text-sm font-medium">{{ $m->name }}</span>
                                    <span class="text-xs text-zinc-500">{{ $m->part }}</span>
                                    @php $mRole = \App\Enums\MinistryRole::tryFrom($m->pivot->role) ?? \App\Enums\MinistryRole::BACKUP; @endphp
                                    @if ($mRole->isLead())<flux:badge size="sm" :color="$mRole->color()" icon="star">{{ $mRole->label() }}</flux:badge>@endif
                                </div>
                                <div class="flex gap-1">
                                    @foreach (\App\Enums\MinistryRole::leads() as $role)
                                        @if ($mRole !== $role && $m->canLead($role))
                                            <flux:button size="xs" variant="ghost" wire:click="setRole({{ $m->id }}, '{{ $role->value }}')">{{ $role->shortLabel() }} lead</flux:button>
                                        @endif
                                    @endforeach
                                    @if ($mRole->isLead())
                                        <flux:button size="xs" variant="ghost" wire:click="setRole({{ $m->id }}, 'backup')">Make backup</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeMember({{ $m->id }})" aria-label="Remove from team" />
                                </div>
                            </div>
                        @empty
                            <flux:text size="sm">Nobody assigned yet.</flux:text>
                        @endforelse
                    </div>

                    <form wire:submit="addMember" class="flex flex-wrap items-end gap-2">
                        <flux:select wire:model="addMemberId" label="Add member" class="min-w-56 flex-1">
                            <flux:select.option value="">Choose…</flux:select.option>
                            @foreach ($this->candidates as $c)
                                <flux:select.option :value="$c['member']->id" :disabled="(bool) $c['conflict']">
                                    {{ $c['member']->name }}{{ $c['member']->leadLabel() ? ' (leads '.strtolower($c['member']->leadLabel()).')' : '' }}{{ $c['conflict'] ? ' (busy)' : '' }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="addRole" class="w-40">
                            @foreach (\App\Enums\MinistryRole::cases() as $role)
                                <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                    </form>
                    <flux:error name="addMemberId" />

                    <div class="flex flex-wrap justify-between gap-2">
                        <div class="flex gap-2">
                            <flux:button size="sm" icon="sparkles" wire:click="autoFill">Auto-fill free members</flux:button>
                            @can('send-whatsapp')
                                <flux:button size="sm" icon="chat-bubble-left-right" x-on:click="$flux.modal('manage').close(); $dispatch('whatsapp-share', { type: 'meeting', id: {{ $s->id }} })">Announce on WhatsApp</flux:button>
                            @endcan
                        </div>
                        <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteService({{ $s->id }})" wire:confirm="Remove this service from the roster?">Delete service</flux:button>
                    </div>
                </div>

                {{-- Uniform --}}
                <div x-show="tab === 'uniform'" x-cloak class="space-y-3">
                    @forelse ($this->uniforms as $u)
                        <button type="button" wire:click="setUniform({{ $u->id }})" wire:key="pick-uniform-{{ $u->id }}"
                            @class(['w-full rounded-lg border p-3 text-start transition', 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' => $s->uniform_id === $u->id, 'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-white/5' => $s->uniform_id !== $u->id])>
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{ $u->name }}</span>
                                @if ($s->uniform_id === $u->id)<flux:icon.check-circle variant="mini" class="text-indigo-500" />@endif
                            </div>
                            <div class="mt-1 text-xs text-zinc-500">Top: {{ $u->top }} · Ladies: {{ $u->ladies_bottom }} · Men: {{ $u->men_bottom }}</div>
                        </button>
                    @empty
                        <flux:text size="sm">No uniforms yet. <flux:link :href="route('uniforms')" wire:navigate>Add or get AI suggestions</flux:link>.</flux:text>
                    @endforelse
                    @if ($s->uniform_id)
                        <flux:button size="sm" variant="ghost" wire:click="setUniform(null)">Clear uniform</flux:button>
                    @endif
                </div>

                {{-- Setlist --}}
                <div x-show="tab === 'songs'" x-cloak class="space-y-3">
                    <ol class="space-y-1">
                        @forelse ($s->songs as $song)
                            <li class="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5" wire:key="set-{{ $song->id }}">
                                <span class="text-sm"><span class="me-2 text-zinc-400">{{ $loop->iteration }}.</span>{{ $song->title }}
                                    <span class="text-xs text-zinc-500">· {{ $song->language->label() }}{{ $song->musical_key ? ' · '.$song->musical_key : '' }}</span></span>
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeSong({{ $song->id }})" aria-label="Remove song" />
                            </li>
                        @empty
                            <flux:text size="sm">No songs planned yet.</flux:text>
                        @endforelse
                    </ol>
                    <form wire:submit="addSong" class="flex items-end gap-2">
                        <flux:select wire:model="addSongId" label="Add song" class="flex-1">
                            <flux:select.option value="">Choose from the library…</flux:select.option>
                            @foreach ($this->songOptions as $song)
                                <flux:select.option :value="$song->id">{{ $song->title }} ({{ $song->language->label() }})</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" icon="plus">Add</flux:button>
                    </form>
                    <flux:error name="addSongId" />
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- AI announcement --}}
    <flux:modal name="announcement" class="w-full md:w-2xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Roster announcement</flux:heading>
                <flux:text class="mt-1">Gemini drafts a WhatsApp message from the {{ $this->start->format('F') }} roster. Check it, then copy and share.</flux:text>
            </div>
            <div class="flex items-end gap-2">
                <flux:select wire:model="announcementLanguage" label="Language" class="w-40">
                    <flux:select.option value="english">English</flux:select.option>
                    <flux:select.option value="swahili">Kiswahili</flux:select.option>
                </flux:select>
                <flux:button variant="primary" icon="sparkles" wire:click="draftAnnouncement">{{ $announcement ? 'Regenerate' : 'Draft message' }}</flux:button>
            </div>

            <div wire:loading wire:target="draftAnnouncement" class="w-full space-y-2">
                <flux:skeleton class="h-4 w-3/4" animate="shimmer" />
                <flux:skeleton class="h-4 w-full" animate="shimmer" />
                <flux:skeleton class="h-4 w-5/6" animate="shimmer" />
            </div>

            @if ($announcement)
                <div wire:loading.remove wire:target="draftAnnouncement" x-data="{ copied: false }" class="space-y-2">
                    <flux:textarea wire:model="announcement" rows="14" />
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText($wire.announcement); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-text="copied ? 'Copied!' : 'Copy to clipboard'"></span>
                        </flux:button>
                        @can('send-whatsapp')
                            <flux:button size="sm" variant="primary" icon="chat-bubble-left-right"
                                x-on:click="$flux.modal('announcement').close(); $dispatch('whatsapp-share', { type: 'announcement', month: '{{ $this->start->format('Y-m') }}', text: $wire.announcement })">
                                Send to WhatsApp
                            </flux:button>
                        @endcan
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
