<?php

use App\Livewire\Guard;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingAttendance;
use App\Models\Member;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Meeting'), Guard('meetings.manage')] class extends Component {
    public Meeting $meeting;

    public string $minutes = '';
    public string $actionText = '';
    public ?int $actionMember = null;
    public ?string $actionDue = null;

    /** @var array<int, string> member id => note */
    public array $notes = [];

    public function mount(Meeting $meeting): void
    {
        $this->meeting = $meeting;
        $this->minutes = (string) $meeting->minutes;
        $this->notes = $meeting->attendances()->whereNotNull('note')->pluck('note', 'member_id')->all();
    }

    #[Computed]
    public function members()
    {
        // Active members, plus anyone already on this register (even if inactive now).
        $recorded = $this->meeting->attendances()->pluck('member_id');

        return Member::query()->where(fn ($q) => $q->active()->orWhereIn('id', $recorded))->orderBy('name')->get(['id', 'name', 'part']);
    }

    #[Computed]
    public function register(): array
    {
        return $this->meeting->attendances()->pluck('status', 'member_id')->all();
    }

    #[Computed]
    public function actions()
    {
        return $this->meeting->actions()->with('member:id,name')->get();
    }

    public function mark(int $memberId, string $status): void
    {
        abort_unless(array_key_exists($status, Meeting::ATTENDANCE), 422);
        Member::findOrFail($memberId);

        $current = $this->register[$memberId] ?? null;
        if ($current === $status) {
            MeetingAttendance::where('meeting_id', $this->meeting->id)->where('member_id', $memberId)->first()?->delete();
        } else {
            MeetingAttendance::updateOrCreate(['meeting_id' => $this->meeting->id, 'member_id' => $memberId], ['status' => $status]);
        }
        unset($this->register);
    }

    /** Everyone not yet marked becomes absent. */
    public function markRestAbsent(): void
    {
        foreach ($this->members as $m) {
            if (! isset($this->register[$m->id])) {
                MeetingAttendance::create(['meeting_id' => $this->meeting->id, 'member_id' => $m->id, 'status' => 'absent']);
            }
        }
        unset($this->register);
    }

    public function saveNote(int $memberId): void
    {
        $note = trim((string) ($this->notes[$memberId] ?? '')) ?: null;
        MeetingAttendance::where('meeting_id', $this->meeting->id)->where('member_id', $memberId)->first()?->update(['note' => $note ? mb_substr($note, 0, 255) : null]);
    }

    public function saveMinutes(): void
    {
        $this->validate(['minutes' => ['nullable', 'string', 'max:60000']]);
        $this->meeting->update(['minutes' => $this->minutes ?: null]);
        Flux::toast(variant: 'success', text: 'Minutes saved.');
    }

    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, ['scheduled', 'held', 'cancelled'], true), 422);
        $this->meeting->update(['status' => $status]);
        Flux::toast(text: 'Meeting marked as '.$status.'.');
    }

    public function addAction(): void
    {
        $this->validate([
            'actionText' => ['required', 'string', 'max:255'],
            'actionMember' => ['nullable', 'exists:members,id'],
            'actionDue' => ['nullable', 'date'],
        ], [], ['actionText' => 'action']);

        $this->meeting->actions()->create(['description' => $this->actionText, 'member_id' => $this->actionMember, 'due_date' => $this->actionDue ?: null]);
        $this->reset(['actionText', 'actionMember', 'actionDue']);
        unset($this->actions);
    }

    public function toggleAction(int $id): void
    {
        $a = MeetingAction::where('meeting_id', $this->meeting->id)->findOrFail($id);
        $a->update(['done_at' => $a->done_at ? null : now()]);
        unset($this->actions);
    }

    public function deleteAction(int $id): void
    {
        MeetingAction::where('meeting_id', $this->meeting->id)->findOrFail($id)->delete();
        unset($this->actions);
    }
}; ?>

@php
    $canManage = auth()->user()->can('meetings.manage');
    $register = $this->register;
    $counts = collect($register)->countBy();
    $colors = ['present' => 'green', 'late' => 'amber', 'apology' => 'blue', 'absent' => 'red'];
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('meetings')" wire:navigate class="-ms-2 mb-1">Meetings</flux:button>
            <flux:heading size="xl" level="1">{{ $meeting->title }}</flux:heading>
            <flux:subheading>
                {{ $meeting->typeLabel() }} · {{ $meeting->date->format('l, j F Y') }}{{ $meeting->timeLabel() ? ' · '.$meeting->timeLabel() : '' }}{{ $meeting->venue ? ' · '.$meeting->venue : '' }}
            </flux:subheading>
            <div class="mt-2 flex flex-wrap gap-2 text-sm text-zinc-500">
                <flux:badge size="sm" :color="$meeting->statusColor()">{{ ucfirst($meeting->status) }}</flux:badge>
                @if ($meeting->chair) <span>Chair: {{ $meeting->chair->name }}</span> @endif
                @if ($meeting->minuteTaker) <span>· Minutes: {{ $meeting->minuteTaker->name }}</span> @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="document-text" :href="route('exports.meeting', $meeting)">Minutes PDF</flux:button>
            @if ($canManage)
                @if ($meeting->status !== 'held')
                    <flux:button variant="primary" icon="check" wire:click="setStatus('held')">Mark as held</flux:button>
                @else
                    <flux:button variant="ghost" wire:click="setStatus('scheduled')">Reopen</flux:button>
                @endif
            @endif
        </div>
    </div>

    @if ($meeting->agenda)
        <flux:card class="space-y-2">
            <flux:heading size="lg">Agenda</flux:heading>
            <ol class="list-decimal space-y-1 ps-5 text-sm">
                @foreach (preg_split('/\r?\n/', trim($meeting->agenda)) as $item)
                    @if (trim($item) !== '') <li>{{ ltrim(trim($item), '-•0123456789.) ') }}</li> @endif
                @endforeach
            </ol>
        </flux:card>
    @endif

    <div class="grid gap-4 lg:grid-cols-5">
        <flux:card class="space-y-3 lg:col-span-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <flux:heading size="lg">Attendance register</flux:heading>
                    <flux:text size="sm">
                        {{ ($counts['present'] ?? 0) + ($counts['late'] ?? 0) }} present · {{ $counts['apology'] ?? 0 }} apologies · {{ $counts['absent'] ?? 0 }} absent · {{ $this->members->count() - count($register) }} not marked
                    </flux:text>
                </div>
                @if ($canManage && count($register) < $this->members->count())
                    <flux:button size="sm" variant="ghost" wire:click="markRestAbsent" wire:confirm="Mark everyone not yet marked as absent?">Mark the rest absent</flux:button>
                @endif
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach ($this->members as $m)
                    @php $status = $register[$m->id] ?? null; @endphp
                    <div wire:key="att-{{ $m->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="min-w-40">
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $m->name }}</div>
                            @if ($status && $canManage)
                                <input type="text" wire:model="notes.{{ $m->id }}" wire:blur="saveNote({{ $m->id }})" placeholder="Note (e.g. reason)" class="mt-0.5 w-full border-0 bg-transparent p-0 text-xs text-zinc-500 focus:ring-0" />
                            @elseif (! empty($notes[$m->id]))
                                <div class="text-xs text-zinc-500">{{ $notes[$m->id] }}</div>
                            @endif
                        </div>
                        <div class="flex gap-1">
                            @foreach (Meeting::ATTENDANCE as $value => $label)
                                @if ($canManage)
                                    <flux:button size="xs" :variant="$status === $value ? 'primary' : 'ghost'" :color="$status === $value ? $colors[$value] : null" wire:click="mark({{ $m->id }}, '{{ $value }}')">{{ $label }}</flux:button>
                                @elseif ($status === $value)
                                    <flux:badge size="sm" :color="$colors[$value]">{{ $label }}</flux:badge>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>

        <div class="space-y-4 lg:col-span-2">
            <flux:card class="space-y-3">
                <flux:heading size="lg">Minutes</flux:heading>
                @if ($canManage)
                    <form wire:submit="saveMinutes" class="space-y-2">
                        <flux:textarea wire:model="minutes" rows="14" placeholder="Opening prayer…&#10;1. Matters arising…&#10;2. …&#10;Closing prayer and adjournment." />
                        <div class="flex justify-end"><flux:button type="submit" size="sm" variant="primary">Save minutes</flux:button></div>
                    </form>
                @else
                    <div class="whitespace-pre-line text-sm">{{ $meeting->minutes ?: 'No minutes yet.' }}</div>
                @endif
            </flux:card>

            <flux:card class="space-y-3">
                <flux:heading size="lg">Action items</flux:heading>
                @forelse ($this->actions as $a)
                    <div wire:key="action-{{ $a->id }}" class="flex items-start justify-between gap-2 text-sm">
                        <div class="flex items-start gap-2">
                            @if ($canManage)
                                <flux:checkbox :checked="(bool) $a->done_at" wire:click="toggleAction({{ $a->id }})" />
                            @endif
                            <div>
                                <div @class(['line-through text-zinc-400' => $a->done_at])>{{ $a->description }}</div>
                                <div class="text-xs text-zinc-500">{{ $a->member->name ?? 'Unassigned' }}{{ $a->due_date ? ' · due '.$a->due_date->format('j M') : '' }}</div>
                            </div>
                        </div>
                        @if ($canManage)
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="deleteAction({{ $a->id }})" aria-label="Remove" />
                        @endif
                    </div>
                @empty
                    <flux:text size="sm">No action items.</flux:text>
                @endforelse

                @if ($canManage)
                    <form wire:submit="addAction" class="space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                        <flux:input wire:model="actionText" size="sm" placeholder="What needs to be done" />
                        <div class="grid grid-cols-2 gap-2">
                            <flux:select wire:model="actionMember" size="sm">
                                <flux:select.option value="">Who?</flux:select.option>
                                @foreach ($this->members as $m)
                                    <flux:select.option value="{{ $m->id }}">{{ $m->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="actionDue" type="date" size="sm" />
                        </div>
                        <flux:button type="submit" size="sm" icon="plus" class="w-full">Add action</flux:button>
                    </form>
                @endif
            </flux:card>
        </div>
    </div>
</div>
