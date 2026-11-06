<?php

use App\Enums\Gender;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberReview;
use App\Models\MemberUnavailability;
use App\Models\Setting;
use App\Support\Phone;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Team members')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'current';

    // Member form
    public ?int $editingId = null;
    public string $name = '';
    public string $gender = 'female';
    public string $phone = '';
    public string $email = '';
    public string $part = '';
    public bool $can_lead_praise = false;
    public bool $can_lead_worship = false;
    public string $status = 'probation';
    public ?string $joined_at = null;
    public ?string $probation_ends_at = null;
    public string $notes = '';

    // Unavailability form
    public ?int $availabilityMemberId = null;
    public ?string $unavailableDate = null;
    public string $unavailableReason = '';

    // Probation & behaviour notes
    public ?int $probationMemberId = null;
    public string $reviewKind = 'note';
    public string $reviewNote = '';
    public int $extendWeeks = 4;
    public ?string $newProbationEnd = null;

    // Team setting: default probation length
    public int $probationWeeks = 12;

    public function mount(): void
    {
        $this->probationWeeks = Setting::probationWeeks();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function members()
    {
        return Member::query()
            ->withCount(['ministrations as upcoming_count' => fn ($q) => $q->whereDate('date', '>=', today())])
            ->withCount(['tasks as open_tasks_count' => fn ($q) => $q->open()])
            ->withCount('reviews')
            ->with(['unavailabilities' => fn ($q) => $q->whereDate('date', '>=', today())->orderBy('date')])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('part', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")))
            ->when(in_array($this->filter, ['current', 'active'], true), fn ($q) => $q->where('status', '!=', MemberStatus::INACTIVE->value))
            ->when($this->filter === 'confirmed', fn ($q) => $q->where('status', MemberStatus::ACTIVE->value))
            ->when($this->filter === 'probation', fn ($q) => $q->probationers())
            ->when($this->filter === 'praise', fn ($q) => $q->where('status', MemberStatus::ACTIVE->value)->where('can_lead_praise', true))
            ->when($this->filter === 'worship', fn ($q) => $q->where('status', MemberStatus::ACTIVE->value)->where('can_lead_worship', true))
            ->when($this->filter === 'inactive', fn ($q) => $q->where('status', MemberStatus::INACTIVE->value))
            ->orderByRaw("status = 'probation' desc")
            ->orderBy('name')
            ->paginate(24);
    }

    #[Computed]
    public function counts(): array
    {
        $probation = Member::probationers()->get(['id', 'status', 'joined_at', 'probation_ends_at']);

        return [
            'active' => Member::where('status', MemberStatus::ACTIVE->value)->count(),
            'probation' => $probation->count(),
            'due' => $probation->filter->probationDue()->count(),
            'praise' => Member::where('status', MemberStatus::ACTIVE->value)->where('can_lead_praise', true)->count(),
            'worship' => Member::where('status', MemberStatus::ACTIVE->value)->where('can_lead_worship', true)->count(),
        ];
    }

    /** Current members we can't reach: no phone number on file. */
    #[Computed]
    public function missingPhones()
    {
        return Member::where('status', '!=', MemberStatus::INACTIVE->value)
            ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
            ->orderBy('name')->get(['id', 'name']);
    }

    /** Members whose probation has ended and need a decision. */
    #[Computed]
    public function dueForDecision()
    {
        return Member::probationers()->whereDate('probation_ends_at', '<=', today())->orderBy('probation_ends_at')->get();
    }

    #[Computed]
    public function availabilityMember(): ?Member
    {
        return $this->availabilityMemberId
            ? Member::with(['unavailabilities' => fn ($q) => $q->orderBy('date')])->find($this->availabilityMemberId)
            : null;
    }

    #[Computed]
    public function probationMember(): ?Member
    {
        return $this->probationMemberId
            ? Member::with(['reviews.author:id,name'])->find($this->probationMemberId)
            : null;
    }

    // --- Member CRUD ----------------------------------------------------

    public function newMember(): void
    {
        $this->reset('editingId', 'name', 'gender', 'phone', 'email', 'part', 'can_lead_praise', 'can_lead_worship', 'notes', 'probation_ends_at');
        $this->status = MemberStatus::PROBATION->value; // new members start on probation
        $this->joined_at = today()->toDateString();
        $this->resetValidation();
        Flux::modal('member-form')->show();
    }

    public function edit(int $id): void
    {
        $m = Member::findOrFail($id);
        $this->editingId = $m->id;
        $this->name = $m->name;
        $this->gender = $m->gender->value;
        $this->phone = (string) $m->phone;
        $this->email = (string) $m->email;
        $this->part = (string) $m->part;
        $this->can_lead_praise = $m->can_lead_praise;
        $this->can_lead_worship = $m->can_lead_worship;
        $this->status = $m->status->value;
        $this->joined_at = $m->joined_at?->toDateString();
        $this->probation_ends_at = $m->probation_ends_at?->toDateString();
        $this->notes = (string) $m->notes;
        $this->resetValidation();
        Flux::modal('member-form')->show();
    }

    public function save(): void
    {
        // Tidy the number first so "0712 345 678" and "+254712345678" are treated as the same.
        $this->phone = Phone::normalize($this->phone) ?? trim($this->phone);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'phone' => [
                'required',
                fn ($attr, $value, $fail) => Phone::normalize($value) ? null : $fail('Enter a valid phone number, e.g. 0712 345 678 or +254712345678.'),
                Rule::unique('members', 'phone')->ignore($this->editingId),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'part' => ['nullable', 'string', 'max:50'],
            'can_lead_praise' => ['boolean'],
            'can_lead_worship' => ['boolean'],
            'status' => ['required', Rule::enum(MemberStatus::class)],
            'joined_at' => ['required', 'date'],
            'probation_ends_at' => ['nullable', 'date', 'after:joined_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'phone.unique' => 'Another member already has this phone number.',
        ], ['joined_at' => 'date joined', 'probation_ends_at' => 'probation end date']);

        $data['probation_ends_at'] = $data['probation_ends_at'] ?: null;

        Member::updateOrCreate(['id' => $this->editingId], $data);

        Flux::modal('member-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? 'Member updated.' : 'Member added.');
        $this->refreshData();
    }

    public function delete(int $id): void
    {
        Member::findOrFail($id)->delete();
        Flux::toast(text: 'Member removed.');
        $this->refreshData();
    }

    // --- Probation --------------------------------------------------------

    public function openProbation(int $id): void
    {
        $this->probationMemberId = $id;
        $this->reset('reviewNote', 'newProbationEnd');
        $this->reviewKind = 'note';
        $this->extendWeeks = 4;
        $this->resetValidation();
        unset($this->probationMember);
        Flux::modal('probation')->show();
    }

    public function addReview(): void
    {
        $this->validate([
            'reviewKind' => ['required', Rule::in(array_keys(MemberReview::KINDS))],
            'reviewNote' => ['required', 'string', 'max:2000'],
        ], attributes: ['reviewNote' => 'note']);

        MemberReview::create([
            'member_id' => $this->probationMemberId,
            'user_id' => auth()->id(),
            'kind' => $this->reviewKind,
            'note' => $this->reviewNote,
        ]);

        $this->reset('reviewNote');
        $this->reviewKind = 'note';
        $this->refreshData();
    }

    public function deleteReview(int $id): void
    {
        MemberReview::where('member_id', $this->probationMemberId)->findOrFail($id)->delete();
        $this->refreshData();
    }

    public function extendProbation(): void
    {
        $this->validate(['extendWeeks' => ['required', 'integer', 'min:1', 'max:104']], attributes: ['extendWeeks' => 'weeks']);

        $member = Member::findOrFail($this->probationMemberId);
        $member->extendProbation($this->extendWeeks);
        Flux::toast(variant: 'success', text: "Probation extended to {$member->probation_ends_at->format('j M Y')}.");
        $this->refreshData();
    }

    public function setProbationEnd(): void
    {
        $member = Member::findOrFail($this->probationMemberId);
        $this->validate(['newProbationEnd' => ['required', 'date', 'after:'.$member->joined_at->toDateString()]], attributes: ['newProbationEnd' => 'end date']);

        $member->update(['status' => MemberStatus::PROBATION, 'probation_ends_at' => $this->newProbationEnd]);
        $this->reset('newProbationEnd');
        Flux::toast(variant: 'success', text: 'Probation end date updated.');
        $this->refreshData();
    }

    public function startProbation(int $id): void
    {
        Member::findOrFail($id)->update([
            'status' => MemberStatus::PROBATION,
            'probation_ends_at' => today()->addWeeks(Setting::probationWeeks()),
        ]);
        $this->refreshData();
    }

    public function confirmMember(int $id): void
    {
        $member = Member::findOrFail($id);
        $member->confirm();
        Flux::modal('probation')->close();
        Flux::toast(variant: 'success', text: "{$member->name} is now a confirmed member.");
        $this->refreshData();
    }

    public function discontinueMember(int $id): void
    {
        $member = Member::findOrFail($id);
        $member->discontinue();
        Flux::modal('probation')->close();
        Flux::toast(text: "{$member->name} has been marked inactive.");
        $this->refreshData();
    }

    public function saveProbationSettings(): void
    {
        $this->validate(['probationWeeks' => ['required', 'integer', 'min:1', 'max:104']], attributes: ['probationWeeks' => 'probation length']);

        Setting::set('probation_weeks', (string) $this->probationWeeks);
        Flux::modal('probation-settings')->close();
        Flux::toast(variant: 'success', text: "New members will now be on probation for {$this->probationWeeks} weeks.");
    }

    // --- Unavailability ---------------------------------------------------

    public function manageAvailability(int $id): void
    {
        $this->availabilityMemberId = $id;
        $this->reset('unavailableDate', 'unavailableReason');
        $this->resetValidation();
        unset($this->availabilityMember);
        Flux::modal('availability')->show();
    }

    public function addUnavailable(): void
    {
        $this->validate([
            'unavailableDate' => ['required', 'date'],
            'unavailableReason' => ['nullable', 'string', 'max:255'],
        ], attributes: ['unavailableDate' => 'date']);

        MemberUnavailability::updateOrCreate(
            ['member_id' => $this->availabilityMemberId, 'date' => $this->unavailableDate],
            ['reason' => $this->unavailableReason ?: null],
        );

        $this->reset('unavailableDate', 'unavailableReason');
        unset($this->availabilityMember, $this->members);
        Flux::toast(variant: 'success', text: 'Date blocked. They will not be rostered that day.');
    }

    public function removeUnavailable(int $id): void
    {
        MemberUnavailability::where('member_id', $this->availabilityMemberId)->findOrFail($id)->delete();
        unset($this->availabilityMember, $this->members);
    }

    protected function refreshData(): void
    {
        unset($this->members, $this->counts, $this->dueForDecision, $this->probationMember, $this->missingPhones);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Team members</flux:heading>
            <flux:subheading>
                {{ $this->counts['active'] }} confirmed · {{ $this->counts['probation'] }} on probation ·
                {{ $this->counts['praise'] }} can lead praise · {{ $this->counts['worship'] }} can lead worship
            </flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:dropdown>
                <flux:button icon="arrow-down-tray" icon:trailing="chevron-down">Download</flux:button>
                <flux:menu>
                    <flux:menu.heading>Members list ({{ $filter === 'all' ? 'everyone' : $filter }})</flux:menu.heading>
                    <flux:menu.item icon="document-text" :href="route('exports.members', ['format' => 'pdf', 'filter' => $filter])">PDF</flux:menu.item>
                    <flux:menu.item icon="table-cells" :href="route('exports.members', ['format' => 'xlsx', 'filter' => $filter])">Excel</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
            <flux:modal.trigger name="probation-settings">
                <flux:button icon="clock">Probation: {{ $probationWeeks }} weeks</flux:button>
            </flux:modal.trigger>
            <flux:button variant="primary" icon="user-plus" wire:click="newMember">Add member</flux:button>
        </div>
    </div>

    @if ($this->dueForDecision->isNotEmpty())
        <flux:callout variant="warning" icon="clock">
            <flux:callout.heading>{{ $this->dueForDecision->count() }} {{ Str::plural('member', $this->dueForDecision->count()) }} finished probation: decide whether to confirm, extend or discontinue</flux:callout.heading>
            <flux:callout.text>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($this->dueForDecision as $m)
                        <flux:button size="xs" wire:click="openProbation({{ $m->id }})">{{ $m->name }} · ended {{ $m->probation_ends_at->format('j M') }}</flux:button>
                    @endforeach
                </div>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->missingPhones->isNotEmpty())
        <flux:callout variant="danger" icon="phone-x-mark">
            <flux:callout.heading>{{ $this->missingPhones->count() }} {{ Str::plural('member', $this->missingPhones->count()) }} without a phone number: add one so you can reach them</flux:callout.heading>
            <flux:callout.text>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($this->missingPhones as $m)
                        <flux:button size="xs" icon="plus" wire:click="edit({{ $m->id }})">{{ $m->name }}</flux:button>
                    @endforeach
                </div>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search name, part, phone..." clearable class="sm:max-w-xs" />
        <flux:select wire:model.live="filter" class="sm:max-w-52">
            <flux:select.option value="current">Current (confirmed + probation)</flux:select.option>
            <flux:select.option value="confirmed">Confirmed</flux:select.option>
            <flux:select.option value="probation">On probation</flux:select.option>
            <flux:select.option value="praise">Can lead praise</flux:select.option>
            <flux:select.option value="worship">Can lead worship</flux:select.option>
            <flux:select.option value="inactive">Inactive</flux:select.option>
            <flux:select.option value="all">Everyone</flux:select.option>
        </flux:select>
    </div>

    @if ($this->members->isEmpty())
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <flux:icon.users class="size-10 text-zinc-300 dark:text-zinc-600" />
            <flux:heading>No members here</flux:heading>
            <flux:text>Add your singers and instrumentalists so you can roster them.</flux:text>
            <flux:button size="sm" variant="primary" icon="user-plus" wire:click="newMember">Add member</flux:button>
        </flux:card>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->members as $member)
                <flux:card wire:key="member-{{ $member->id }}" @class(['flex flex-col gap-3 p-4!', 'border-amber-300! dark:border-amber-500/40!' => $member->onProbation()])>
                    <div class="flex items-start gap-3">
                        <flux:avatar :name="$member->name" :initials="$member->initials()" color="auto" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <flux:heading class="truncate">{{ $member->name }}</flux:heading>
                                @if ($member->status !== \App\Enums\MemberStatus::ACTIVE)
                                    <flux:badge size="sm" :color="$member->status->color()">{{ $member->status->label() }}</flux:badge>
                                @endif
                                @if ($member->can_lead_praise)
                                    <flux:badge size="sm" color="amber" icon="star">Praise</flux:badge>
                                @endif
                                @if ($member->can_lead_worship)
                                    <flux:badge size="sm" color="violet" icon="star">Worship</flux:badge>
                                @endif
                            </div>
                            <flux:text size="sm">{{ $member->part ?: 'No part set' }} · {{ $member->gender->label() }}</flux:text>
                            @if ($member->phone)
                                <div class="mt-1 flex items-center gap-1">
                                    <span class="me-1 whitespace-nowrap text-sm tabular-nums text-zinc-700 dark:text-zinc-300">{{ $member->phoneDisplay() }}</span>
                                    <flux:button size="xs" variant="ghost" icon="phone" :href="$member->telUrl()" aria-label="Call {{ $member->name }}" title="Call" />
                                    <flux:button size="xs" variant="ghost" icon="chat-bubble-left" :href="$member->smsUrl()" aria-label="SMS {{ $member->name }}" title="SMS" />
                                    <flux:button size="xs" variant="ghost" :href="$member->whatsappUrl()" target="_blank" aria-label="WhatsApp {{ $member->name }}" title="WhatsApp">
                                        <svg viewBox="0 0 24 24" class="size-4 fill-green-600" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.1-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5c.2-.2.2-.3.3-.5.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.1-.3-.2-.6-.3zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.3c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.3 8.3 0 1 1 12 20.3z"/></svg>
                                    </flux:button>
                                </div>
                            @else
                                <button type="button" wire:click="edit({{ $member->id }})" class="mt-1 text-sm text-red-600 hover:underline dark:text-red-400">No phone number: add one</button>
                            @endif
                        </div>
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="Member actions" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $member->id }})">Edit</flux:menu.item>
                                <flux:menu.item icon="banknotes" :href="route('contributions.member', $member)" wire:navigate>Contributions</flux:menu.item>
                                <flux:menu.item icon="clipboard-document-check" wire:click="openProbation({{ $member->id }})">Probation &amp; notes</flux:menu.item>
                                <flux:menu.item icon="calendar-days" wire:click="manageAvailability({{ $member->id }})">Unavailable dates</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from the team? Their roster slots and payment records will be deleted. To keep their history, mark them inactive instead.">Remove</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    @if ($member->onProbation())
                        @php $left = $member->probationDaysLeft(); @endphp
                        <button type="button" wire:click="openProbation({{ $member->id }})" class="space-y-1 rounded-lg bg-amber-50 p-2 text-start dark:bg-amber-500/10">
                            <div class="flex justify-between text-xs">
                                <span class="font-medium text-amber-800 dark:text-amber-300">
                                    @if ($left <= 0) Probation ended: decision due @else Probation · {{ $left }} {{ Str::plural('day', $left) }} left @endif
                                </span>
                                <span class="text-amber-700/80 dark:text-amber-300/70">ends {{ $member->probation_ends_at?->format('j M Y') }}</span>
                            </div>
                            <flux:progress :value="$member->probationProgress()" :color="$left <= 0 ? 'red' : 'amber'" />
                        </button>
                    @endif

                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="flex items-center gap-1"><flux:icon.musical-note variant="micro" /> {{ $member->upcoming_count }} upcoming</span>
                        <span class="flex items-center gap-1"><flux:icon.clipboard-document-list variant="micro" /> {{ $member->open_tasks_count }} open tasks</span>
                        @if ($member->reviews_count)
                            <button type="button" wire:click="openProbation({{ $member->id }})" class="flex items-center gap-1 hover:underline"><flux:icon.chat-bubble-left-ellipsis variant="micro" /> {{ $member->reviews_count }} {{ Str::plural('note', $member->reviews_count) }}</button>
                        @endif
                        @if ($member->joined_at)
                            <span class="flex items-center gap-1"><flux:icon.calendar variant="micro" /> joined {{ $member->joined_at->format('M Y') }}</span>
                        @endif
                        @if ($member->unavailabilities->isNotEmpty())
                            <button type="button" wire:click="manageAvailability({{ $member->id }})" class="flex items-center gap-1 text-amber-600 hover:underline dark:text-amber-400">
                                <flux:icon.calendar-days variant="micro" /> away {{ $member->unavailabilities->map(fn ($u) => $u->date->format('j M'))->take(3)->implode(', ') }}{{ $member->unavailabilities->count() > 3 ? '…' : '' }}
                            </button>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->members" />
    @endif

    {{-- Add / edit member --}}
    <flux:modal name="member-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit member' : 'Add member' }}</flux:heading>

            <flux:input wire:model="name" label="Full name" />

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="gender" label="Gender" description="Decides dress vs trousers.">
                    @foreach (\App\Enums\Gender::cases() as $g)
                        <flux:select.option :value="$g->value">{{ $g->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="part" label="Part / instrument" placeholder="Alto, Keys..." list="parts" />
                <datalist id="parts">
                    @foreach (['Soprano', 'Alto', 'Tenor', 'Bass', 'Keys', 'Guitar', 'Bass guitar', 'Drums', 'Sound'] as $p)
                        <option value="{{ $p }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="phone" type="tel" label="Phone" placeholder="0712 345 678" description="Required: used for calls, SMS and WhatsApp." />
                <flux:input wire:model="email" type="email" label="Email" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model.live="status" label="Membership status">
                    @foreach (\App\Enums\MemberStatus::cases() as $s)
                        <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="joined_at" type="date" label="Date joined" />
            </div>

            @if ($status === 'probation')
                <flux:input wire:model="probation_ends_at" type="date" label="Probation ends"
                    :description="'Leave empty to use the team default ('.$probationWeeks.' weeks from the date joined).'" />
            @endif

            <flux:switch wire:model="can_lead_praise" label="Can lead the praise session" description="Eligible to be the praise leader once confirmed." />
            <flux:switch wire:model="can_lead_worship" label="Can lead the worship session" description="Eligible to be the worship leader once confirmed." />
            <flux:textarea wire:model="notes" label="Notes" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Probation & behaviour notes --}}
    <flux:modal name="probation" class="w-full md:w-2xl">
        @if ($pm = $this->probationMember)
            <div class="space-y-5">
                <div class="flex items-start justify-between gap-3 pe-8">
                    <div>
                        <flux:heading size="lg">{{ $pm->name }}</flux:heading>
                        <flux:text>
                            Joined {{ $pm->joined_at?->format('j M Y') ?? '—' }}
                            @if ($pm->confirmed_at) · confirmed {{ $pm->confirmed_at->format('j M Y') }} @endif
                        </flux:text>
                    </div>
                    <flux:badge :color="$pm->status->color()">{{ $pm->status->label() }}</flux:badge>
                </div>

                @if ($pm->onProbation())
                    @php $left = $pm->probationDaysLeft(); @endphp
                    <div class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/30 dark:bg-amber-500/5">
                        <div class="flex justify-between text-sm">
                            <span class="font-medium">{{ $pm->joined_at?->format('j M') }} → {{ $pm->probation_ends_at?->format('j M Y') }}</span>
                            <span @class(['font-medium', 'text-red-600 dark:text-red-400' => $left <= 0])>{{ $left > 0 ? $left.' '.Str::plural('day', $left).' left' : 'Ended '.abs($left).' '.Str::plural('day', abs($left)).' ago' }}</span>
                        </div>
                        <flux:progress :value="$pm->probationProgress()" :color="$left <= 0 ? 'red' : 'amber'" />
                        <flux:text size="sm">While on probation, {{ $pm->name }} can be rostered as a backup but not as praise or worship leader.</flux:text>

                        <div class="flex flex-wrap items-end gap-2">
                            <form wire:submit="extendProbation" class="flex items-end gap-2">
                                <flux:input wire:model="extendWeeks" type="number" min="1" max="104" size="sm" label="Extend by (weeks)" class="w-32" />
                                <flux:button size="sm" type="submit" icon="plus">Extend</flux:button>
                            </form>
                            <form wire:submit="setProbationEnd" class="flex items-end gap-2">
                                <flux:input wire:model="newProbationEnd" type="date" size="sm" label="Or set end date" class="w-40" />
                                <flux:button size="sm" type="submit">Set</flux:button>
                            </form>
                        </div>
                        <flux:error name="extendWeeks" />
                        <flux:error name="newProbationEnd" />

                        <div class="flex gap-2 border-t border-amber-200 pt-3 dark:border-amber-500/30">
                            <flux:button size="sm" variant="primary" icon="check-badge" wire:click="confirmMember({{ $pm->id }})" wire:confirm="Confirm {{ $pm->name }} as a full member?">Confirm membership</flux:button>
                            <flux:button size="sm" variant="danger" icon="x-circle" wire:click="discontinueMember({{ $pm->id }})" wire:confirm="Discontinue {{ $pm->name }}? They will be marked inactive and no longer rostered.">Discontinue</flux:button>
                        </div>
                    </div>
                @else
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text size="sm">Not on probation.</flux:text>
                        <flux:button size="sm" wire:click="startProbation({{ $pm->id }})">Put on probation ({{ $probationWeeks }} weeks)</flux:button>
                    </div>
                @endif

                <div class="space-y-3">
                    <flux:heading>Behaviour notes</flux:heading>
                    <form wire:submit="addReview" class="space-y-2">
                        <div class="flex gap-2">
                            <flux:select wire:model="reviewKind" class="w-36">
                                @foreach (\App\Models\MemberReview::KINDS as $value => $label)
                                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="reviewNote" placeholder="e.g. Punctual for rehearsals, great attitude" class="flex-1" />
                            <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                        </div>
                        <flux:error name="reviewNote" />
                    </form>

                    <div class="max-h-72 space-y-2 overflow-y-auto">
                        @forelse ($pm->reviews as $review)
                            <div class="flex items-start justify-between gap-3 rounded-lg border border-zinc-100 p-3 dark:border-zinc-700" wire:key="review-{{ $review->id }}">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <flux:badge size="sm" :color="$review->color()">{{ \App\Models\MemberReview::KINDS[$review->kind] ?? 'Note' }}</flux:badge>
                                        <span class="text-xs text-zinc-500">{{ $review->created_at->format('j M Y') }}{{ $review->author ? ' · '.$review->author->name : '' }}</span>
                                    </div>
                                    <div class="text-sm">{{ $review->note }}</div>
                                </div>
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="deleteReview({{ $review->id }})" wire:confirm="Delete this note?" aria-label="Delete note" />
                            </div>
                        @empty
                            <flux:text size="sm">No notes yet. Record how they're settling in: punctuality, attitude, commitment.</flux:text>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Default probation length --}}
    <flux:modal name="probation-settings" class="md:w-md">
        <form wire:submit="saveProbationSettings" class="space-y-5">
            <div>
                <flux:heading size="lg">Probation period</flux:heading>
                <flux:text class="mt-1">How long new members stay on probation by default. You can still extend or shorten it for any individual.</flux:text>
            </div>
            <flux:input wire:model="probationWeeks" type="number" min="1" max="104" label="Default length (weeks)" />
            <flux:text size="sm">Existing probation end dates are not changed.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Unavailable dates --}}
    <flux:modal name="availability" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Unavailable dates</flux:heading>
                <flux:text class="mt-1">{{ $this->availabilityMember?->name }} won't be rostered on these days.</flux:text>
            </div>

            <form wire:submit="addUnavailable" class="flex flex-wrap items-end gap-2">
                <flux:input wire:model="unavailableDate" type="date" label="Date" class="w-44" />
                <flux:input wire:model="unavailableReason" label="Reason (optional)" class="min-w-40 flex-1" />
                <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
            </form>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->availabilityMember?->unavailabilities ?? [] as $u)
                    <div class="flex items-center justify-between py-2" wire:key="away-{{ $u->id }}">
                        <div>
                            <div @class(['text-sm font-medium', 'text-zinc-400 line-through' => $u->date->isPast() && ! $u->date->isToday()])>{{ $u->date->format('D, j M Y') }}</div>
                            @if ($u->reason)<flux:text size="sm">{{ $u->reason }}</flux:text>@endif
                        </div>
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeUnavailable({{ $u->id }})" aria-label="Remove date" />
                    </div>
                @empty
                    <flux:text size="sm">No blocked dates.</flux:text>
                @endforelse
            </div>
        </div>
    </flux:modal>
</div>
