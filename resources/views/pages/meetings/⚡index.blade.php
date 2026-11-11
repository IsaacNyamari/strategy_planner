<?php

use App\Livewire\Guard;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Meetings'), Guard('meetings.manage')] class extends Component {
    public ?int $editingId = null;
    public string $title = '';
    public string $type = 'general';
    public string $date = '';
    public string $startsAt = '';
    public string $endsAt = '';
    public string $venue = '';
    public string $agenda = '';
    public ?int $chairedBy = null;
    public ?int $minutesBy = null;

    #[Computed]
    public function upcoming()
    {
        return Meeting::with('chair:id,name')->where('status', 'scheduled')->whereDate('date', '>=', today())->orderBy('date')->orderBy('starts_at')->get();
    }

    #[Computed]
    public function past()
    {
        return Meeting::with('chair:id,name')->withCount([
            'attendances as present_count' => fn ($q) => $q->whereIn('status', ['present', 'late']),
            'attendances',
            'actions as open_actions_count' => fn ($q) => $q->whereNull('done_at'),
        ])->where(fn ($q) => $q->where('status', '!=', 'scheduled')->orWhereDate('date', '<', today()))
            ->orderByDesc('date')->limit(50)->get();
    }

    #[Computed]
    public function members()
    {
        return Member::active()->orderBy('name')->get(['id', 'name']);
    }

    /** Attendance rate per member over the last 12 months of held meetings. */
    #[Computed]
    public function attendanceRates()
    {
        $held = Meeting::where('status', 'held')->whereDate('date', '>=', today()->subYear())->pluck('id');
        if ($held->isEmpty()) {
            return collect();
        }
        $rows = MeetingAttendance::whereIn('meeting_id', $held)->get(['member_id', 'status'])->groupBy('member_id');

        return $this->members->map(fn (Member $m) => [
            'member' => $m,
            'attended' => ($rows[$m->id] ?? collect())->whereIn('status', ['present', 'late'])->count(),
            'apologies' => ($rows[$m->id] ?? collect())->where('status', 'apology')->count(),
            'total' => $held->count(),
        ])->sortByDesc('attended')->values();
    }

    public function newMeeting(): void
    {
        $this->resetErrorBag();
        $this->reset(['editingId', 'title', 'startsAt', 'endsAt', 'venue', 'agenda', 'chairedBy', 'minutesBy']);
        $this->type = 'general';
        $this->date = now()->timezone(config('worship.timezone'))->toDateString();
        $this->minutesBy = auth()->user()->member?->id;
        Flux::modal('meeting-form')->show();
    }

    public function edit(int $id): void
    {
        $m = Meeting::findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $m->id;
        $this->title = $m->title;
        $this->type = $m->type;
        $this->date = $m->date->toDateString();
        $this->startsAt = $m->starts_at ? substr($m->starts_at, 0, 5) : '';
        $this->endsAt = $m->ends_at ? substr($m->ends_at, 0, 5) : '';
        $this->venue = (string) $m->venue;
        $this->agenda = (string) $m->agenda;
        $this->chairedBy = $m->chaired_by;
        $this->minutesBy = $m->minutes_by;
        Flux::modal('meeting-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(Meeting::TYPES))],
            'date' => ['required', 'date'],
            'startsAt' => ['nullable', 'date_format:H:i'],
            'endsAt' => ['nullable', 'date_format:H:i', 'after:startsAt'],
            'venue' => ['nullable', 'string', 'max:150'],
            'agenda' => ['nullable', 'string', 'max:5000'],
            'chairedBy' => ['nullable', 'exists:members,id'],
            'minutesBy' => ['nullable', 'exists:members,id'],
        ], [], ['startsAt' => 'start time', 'endsAt' => 'end time']);

        $values = [
            'title' => $data['title'], 'type' => $data['type'], 'date' => $data['date'],
            'starts_at' => $data['startsAt'] ?: null, 'ends_at' => $data['endsAt'] ?: null,
            'venue' => $data['venue'] ?: null, 'agenda' => $data['agenda'] ?: null,
            'chaired_by' => $data['chairedBy'], 'minutes_by' => $data['minutesBy'],
        ];

        if ($this->editingId) {
            Meeting::findOrFail($this->editingId)->update($values);
            Flux::modal('meeting-form')->close();
            Flux::toast(variant: 'success', text: 'Meeting updated.');
            unset($this->upcoming, $this->past);

            return;
        }

        $meeting = Meeting::create([...$values, 'created_by' => auth()->id()]);
        Flux::modal('meeting-form')->close();
        $this->redirectRoute('meetings.show', $meeting, navigate: true);
    }

    public function cancel(int $id): void
    {
        Meeting::findOrFail($id)->update(['status' => 'cancelled']);
        Flux::toast(text: 'Meeting cancelled.');
        unset($this->upcoming, $this->past);
    }

    public function delete(int $id): void
    {
        Meeting::findOrFail($id)->delete();
        Flux::toast(text: 'Meeting deleted.');
        unset($this->upcoming, $this->past);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Meetings</flux:heading>
            <flux:subheading>Team meetings: agenda, attendance register, minutes and action items.</flux:subheading>
        </div>
        @can('meetings.manage')
            <flux:button variant="primary" icon="plus" wire:click="newMeeting">New meeting</flux:button>
        @endcan
    </div>

    <flux:card class="space-y-3">
        <flux:heading size="lg">Coming up</flux:heading>
        @forelse ($this->upcoming as $m)
            <div wire:key="up-{{ $m->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <a href="{{ route('meetings.show', $m) }}" wire:navigate class="flex items-center gap-3">
                    <div class="w-12 rounded-md border border-indigo-200 text-center dark:border-indigo-800">
                        <div class="rounded-t-md bg-indigo-600 text-[10px] font-semibold uppercase text-white">{{ $m->date->format('M') }}</div>
                        <div class="text-lg font-bold">{{ $m->date->format('j') }}</div>
                    </div>
                    <div>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $m->title }}</div>
                        <div class="text-sm text-zinc-500">{{ $m->typeLabel() }} · {{ $m->date->format('l') }}{{ $m->timeLabel() ? ' · '.$m->timeLabel() : '' }}{{ $m->venue ? ' · '.$m->venue : '' }}</div>
                    </div>
                </a>
                <div class="flex gap-1">
                    <flux:button size="sm" :href="route('meetings.show', $m)" wire:navigate>Open</flux:button>
                    @can('meetings.manage')
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $m->id }})" aria-label="Edit" />
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancel({{ $m->id }})" wire:confirm="Cancel this meeting?" aria-label="Cancel meeting" />
                    @endcan
                </div>
            </div>
        @empty
            <flux:text>No meetings scheduled.</flux:text>
        @endforelse
    </flux:card>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="space-y-3 lg:col-span-2">
            <flux:heading size="lg">Past meetings</flux:heading>
            @if ($this->past->isEmpty())
                <flux:text>No past meetings yet.</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Date</flux:table.column>
                        <flux:table.column>Meeting</flux:table.column>
                        <flux:table.column>Attendance</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->past as $m)
                            <flux:table.row wire:key="past-{{ $m->id }}">
                                <flux:table.cell class="whitespace-nowrap">{{ $m->date->format('j M Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    <a href="{{ route('meetings.show', $m) }}" wire:navigate class="font-medium text-zinc-800 hover:underline dark:text-white">{{ $m->title }}</a>
                                    <div class="text-xs text-zinc-500">{{ $m->typeLabel() }}{{ $m->open_actions_count ? ' · '.$m->open_actions_count.' open action(s)' : '' }}</div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $m->attendances_count ? $m->present_count.' / '.$m->attendances_count : '—' }}</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="$m->statusColor()">{{ ucfirst($m->status) }}</flux:badge></flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">
                                    <flux:button size="xs" variant="ghost" icon="document-text" inset="top bottom" :href="route('exports.meeting', $m)" aria-label="Download minutes" title="Download minutes (PDF)" />
                                    @can('meetings.manage')
                                        <flux:button size="xs" variant="ghost" icon="trash" inset="top bottom" wire:click="delete({{ $m->id }})" wire:confirm="Delete this meeting with its attendance and minutes?" aria-label="Delete" />
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>

        <flux:card class="space-y-3">
            <div>
                <flux:heading size="lg">Attendance (12 months)</flux:heading>
                <flux:text size="sm">Meetings attended out of those held.</flux:text>
            </div>
            @forelse ($this->attendanceRates as $r)
                @php $pct = $r['total'] ? (int) round($r['attended'] / $r['total'] * 100) : 0; @endphp
                <div class="space-y-1">
                    <div class="flex justify-between text-sm"><span>{{ $r['member']->name }}</span><span class="tabular-nums text-zinc-500">{{ $r['attended'] }}/{{ $r['total'] }}{{ $r['apologies'] ? ' · '.$r['apologies'].' apol.' : '' }}</span></div>
                    <div class="h-1.5 rounded-full bg-zinc-100 dark:bg-zinc-700"><div @class(['h-1.5 rounded-full', 'bg-green-500' => $pct >= 75, 'bg-amber-500' => $pct >= 50 && $pct < 75, 'bg-red-500' => $pct < 50]) style="width: {{ $pct }}%"></div></div>
                </div>
            @empty
                <flux:text>Shows once a meeting is marked as held.</flux:text>
            @endforelse
        </flux:card>
    </div>

    <flux:modal name="meeting-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit meeting' : 'New meeting' }}</flux:heading>
            <flux:input wire:model="title" label="Title" placeholder="e.g. November team meeting" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="type" label="Type">
                    @foreach (Meeting::TYPES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="date" type="date" label="Date" />
                <flux:input wire:model="startsAt" type="time" label="Starts" />
                <flux:input wire:model="endsAt" type="time" label="Ends" />
            </div>
            <flux:input wire:model="venue" label="Venue" placeholder="Church hall, Zoom…" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="chairedBy" label="Chaired by">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->members as $mem)
                        <flux:select.option value="{{ $mem->id }}">{{ $mem->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="minutesBy" label="Minutes by">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->members as $mem)
                        <flux:select.option value="{{ $mem->id }}">{{ $mem->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:textarea wire:model="agenda" label="Agenda" rows="5" placeholder="One item per line" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
