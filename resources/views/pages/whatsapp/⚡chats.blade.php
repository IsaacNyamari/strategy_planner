<?php

use App\Models\Member;
use App\Models\WhatsappMessage;
use App\WhatsApp\WhatsAppSender;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('WhatsApp chats')] class extends Component {
    #[Url]
    public ?int $member = null;

    public string $reply = '';

    /** Latest message per member (replies from unknown numbers appear as "Unknown"). */
    #[Computed]
    public function conversations()
    {
        $latest = WhatsappMessage::where('kind', 'chat')
            ->select('member_id', DB::raw('MAX(id) as last_id'))
            ->groupBy('member_id');

        $unread = WhatsappMessage::where('kind', 'chat')->where('direction', 'in')->whereNull('read_at')
            ->selectRaw('member_id, count(*) as n')->groupBy('member_id')->pluck('n', 'member_id');

        return WhatsappMessage::with('member:id,name,phone')
            ->joinSub($latest, 'l', 'whatsapp_messages.id', '=', 'l.last_id')
            ->orderByDesc('whatsapp_messages.id')
            ->get(['whatsapp_messages.*'])
            ->map(fn ($m) => ['message' => $m, 'unread' => (int) ($unread[$m->member_id ?? ''] ?? 0)]);
    }

    #[Computed]
    public function current(): ?Member
    {
        return $this->member ? Member::find($this->member) : null;
    }

    #[Computed]
    public function thread()
    {
        return $this->member
            ? WhatsappMessage::with('sender:id,name')->where('kind', 'chat')->where('member_id', $this->member)->orderBy('id')->limit(300)->get()
            : collect();
    }

    #[Computed]
    public function unknown()
    {
        return WhatsappMessage::where('kind', 'chat')->whereNull('member_id')->latest()->limit(50)->get();
    }

    public function open(int $memberId): void
    {
        $this->member = $memberId;
        $this->reset('reply');
        $this->syncHistory(quiet: true);
        $this->markRead();
    }

    /** Pull this member's recent WhatsApp messages (both sides) into the thread. */
    public function syncHistory(bool $quiet = false): void
    {
        $member = $this->member ? Member::find($this->member) : null;

        if (! $member) {
            return;
        }

        try {
            $count = app(\App\WhatsApp\ChatSync::class)->syncMember($member);
            if (! $quiet) {
                Flux::toast(text: $count ? "Imported {$count} ".Str::plural('message', $count).' from WhatsApp.' : 'Already up to date.');
            }
        } catch (\App\WhatsApp\WahaException $e) {
            if (! $quiet) {
                Flux::toast(variant: 'danger', text: $e->getMessage());
            }
        }

        unset($this->conversations, $this->thread);
    }

    public function markRead(): void
    {
        if ($this->member) {
            WhatsappMessage::where('member_id', $this->member)->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);
        }
        unset($this->conversations, $this->thread);
    }

    public function send(): void
    {
        $this->authorize('manage-whatsapp');
        $this->validate(['reply' => ['required', 'string', 'max:4000']], [], ['reply' => 'message']);

        $member = Member::findOrFail($this->member);
        $result = app(WhatsAppSender::class)->sendToMember($member, $this->reply, auth()->user());

        if ($result->status === 'failed') {
            Flux::toast(variant: 'danger', text: $result->error ?? 'Not sent.');

            return;
        }

        $this->reset('reply');
        unset($this->conversations, $this->thread);
    }

    public function refreshChats(): void
    {
        $this->markRead();
    }

    public function mount(): void
    {
        $this->syncHistory(quiet: true); // e.g. arriving from a member's "Message" button
        $this->markRead();
    }
};
?>

<div class="flex flex-col gap-4" wire:poll.5s="refreshChats">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">WhatsApp chats</flux:heading>
            <flux:subheading>One-to-one conversations between the team number and members.</flux:subheading>
        </div>
        <flux:button size="sm" variant="ghost" icon="cog-6-tooth" :href="route('whatsapp')" wire:navigate>WhatsApp settings</flux:button>
    </div>

    <flux:callout icon="shield-exclamation" color="amber" class="text-sm">
        <flux:callout.text>Messages go out one at a time from the team number. WhatsApp limits messages to people who haven't chatted with the team before, so replying works best. For a first contact, ask the member to save the number or message it first.</flux:callout.text>
    </flux:callout>

    <div class="grid min-h-[60vh] gap-4 lg:grid-cols-3">
        {{-- Conversations --}}
        <flux:card class="space-y-1 overflow-y-auto p-2! lg:max-h-[70vh]">
            @forelse ($this->conversations as $c)
                @php $m = $c['message']; @endphp
                @if ($m->member_id)
                    <button type="button" wire:click="open({{ $m->member_id }})" wire:key="conv-{{ $m->member_id }}"
                        @class(['flex w-full items-start justify-between gap-2 rounded-lg p-2 text-start', 'bg-zinc-100 dark:bg-white/10' => $member === $m->member_id, 'hover:bg-zinc-50 dark:hover:bg-white/5' => $member !== $m->member_id])>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">{{ $m->member->name ?? $m->target_name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $m->direction === 'out' ? 'You: ' : '' }}{{ Str::limit($m->body, 50) }}</div>
                        </div>
                        <div class="shrink-0 text-end">
                            <div class="text-xs text-zinc-400">{{ $m->created_at->isToday() ? $m->created_at->format('H:i') : $m->created_at->format('j M') }}</div>
                            @if ($c['unread'])<flux:badge size="sm" color="green">{{ $c['unread'] }}</flux:badge>@endif
                        </div>
                    </button>
                @endif
            @empty
                <flux:text size="sm" class="p-2">No conversations yet. Use “Message” on a member to start one.</flux:text>
            @endforelse

            @if ($this->unknown->isNotEmpty())
                <div class="mt-3 border-t border-zinc-100 px-2 pt-2 text-xs font-medium uppercase text-zinc-400 dark:border-zinc-700">From unknown numbers</div>
                @foreach ($this->unknown->unique('chat_id')->take(10) as $u)
                    <div class="rounded-lg p-2 text-xs text-zinc-500" wire:key="unk-{{ $u->id }}"><span class="font-medium">{{ $u->target_name }}</span>: {{ Str::limit($u->body, 60) }}</div>
                @endforeach
            @endif
        </flux:card>

        {{-- Thread --}}
        <flux:card class="flex flex-col gap-3 lg:col-span-2 lg:max-h-[70vh]">
            @if ($person = $this->current)
                <div class="flex items-center justify-between border-b border-zinc-100 pb-2 dark:border-zinc-700">
                    <div>
                        <flux:heading>{{ $person->name }}</flux:heading>
                        <flux:text size="sm">{{ $person->phoneDisplay() ?: 'no phone number' }}</flux:text>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="syncHistory">Refresh from WhatsApp</flux:button>
                        <flux:button size="xs" variant="ghost" icon="banknotes" :href="route('contributions.member', $person)" wire:navigate>Statement</flux:button>
                    </div>
                </div>

                <div class="flex-1 space-y-2 overflow-y-auto pe-1" x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:livewire:navigated.window="$el.scrollTop = $el.scrollHeight">
                    @forelse ($this->thread as $msg)
                        <div @class(['flex', 'justify-end' => $msg->direction === 'out']) wire:key="t-{{ $msg->id }}">
                            <div @class(['max-w-[80%] rounded-2xl px-3 py-2 text-sm whitespace-pre-line',
                                'bg-green-100 text-green-950 dark:bg-green-900/40 dark:text-green-50' => $msg->direction === 'out',
                                'bg-zinc-100 dark:bg-white/10' => $msg->direction === 'in'])>{{ $msg->body }}
                                <div class="mt-1 text-end text-[10px] opacity-60">
                                    {{ $msg->created_at->format('j M H:i') }}
                                    @if ($msg->direction === 'out') · {{ $msg->status === 'failed' ? 'not sent' : $msg->status }}{{ $msg->sender ? ' · '.$msg->sender->name : '' }} @endif
                                </div>
                                @if ($msg->status === 'failed' && $msg->error)<div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $msg->error }}</div>@endif
                            </div>
                        </div>
                    @empty
                        <flux:text size="sm">No messages yet. Say hello below.</flux:text>
                    @endforelse
                </div>

                <form wire:submit="send" class="flex items-end gap-2 border-t border-zinc-100 pt-2 dark:border-zinc-700">
                    <flux:textarea wire:model="reply" rows="2" placeholder="Type a message…" class="flex-1" />
                    <flux:button type="submit" variant="primary" icon="paper-airplane">
                        <span wire:loading.remove wire:target="send">Send</span><span wire:loading wire:target="send">Sending…</span>
                    </flux:button>
                </form>
                <flux:error name="reply" />
            @else
                <div class="flex flex-1 items-center justify-center"><flux:text>Choose a conversation, or press “Message” on a member.</flux:text></div>
            @endif
        </flux:card>
    </div>
</div>
