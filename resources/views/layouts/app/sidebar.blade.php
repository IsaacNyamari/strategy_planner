<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            @php $me = auth()->user(); @endphp
            <flux:sidebar.group :heading="__('Me')" class="grid">
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:sidebar.item>
                <flux:sidebar.item icon="clipboard-document-list" :href="route('tasks')" :current="request()->routeIs('tasks*')" wire:navigate>{{ $me->can('tasks.view') ? __('Tasks') : __('My tasks') }}</flux:sidebar.item>
                @if ($me->member)
                    <flux:sidebar.item icon="wallet" :href="route('contributions.member', $me->member)" :current="request()->routeIs('contributions.member') && request()->route('member')?->is($me->member)" wire:navigate>{{ __('My contributions') }}</flux:sidebar.item>
                @endif
            </flux:sidebar.group>
            @if ($me->canAny(['strategies.view', 'meetings.view']))
                <flux:sidebar.group :heading="__('Planning')" class="grid">
                    @can('strategies.view')
                        <flux:sidebar.item icon="flag" :href="route('strategies')" :current="request()->routeIs('strategies*')" wire:navigate>{{ __('Strategies') }}</flux:sidebar.item>
                    @endcan
                    @can('meetings.view')
                        <flux:sidebar.item icon="presentation-chart-bar" :href="route('meetings')" :current="request()->routeIs('meetings*')" wire:navigate>{{ __('Meetings') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            @endif
            @if ($me->canAny(['roster.view', 'members.view', 'uniforms.view', 'songs.view', 'schedule-posts']))
                <flux:sidebar.group :heading="__('Ministry')" class="grid">
                    @can('roster.view')
                        <flux:sidebar.item icon="calendar-days" :href="route('roster')" :current="request()->routeIs('roster*')" wire:navigate>{{ __('Roster') }}</flux:sidebar.item>
                    @endcan
                    @can('members.view')
                        <flux:sidebar.item icon="users" :href="route('members')" :current="request()->routeIs('members*')" wire:navigate>{{ __('Members') }}</flux:sidebar.item>
                    @endcan
                    @can('songs.view')
                        <flux:sidebar.item icon="musical-note" :href="route('songs')" :current="request()->routeIs('songs*')" wire:navigate>{{ __('Songs & hymns') }}</flux:sidebar.item>
                    @endcan
                    @can('uniforms.view')
                        <flux:sidebar.item icon="swatch" :href="route('uniforms')" :current="request()->routeIs('uniforms*')" wire:navigate>{{ __('Uniforms') }}</flux:sidebar.item>
                    @endcan
                    @can('schedule-posts')
                        <flux:sidebar.item icon="megaphone" :href="route('posts')" :current="request()->routeIs('posts')" wire:navigate>{{ __('Post scheduler') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            @endif
            @if ($me->canAny(['contributions.view', 'finance.view']))
                <flux:sidebar.group :heading="__('Money')" class="grid">
                    @can('contributions.view')
                        <flux:sidebar.item icon="banknotes" :href="route('contributions')" :current="request()->routeIs('contributions') || (request()->routeIs('contributions.member') && ! request()->route('member')?->is($me->member))" wire:navigate>{{ __('Contributions') }}</flux:sidebar.item>
                    @endcan
                    @can('finance.view')
                        <flux:sidebar.item icon="scale" :href="route('finance')" :current="request()->routeIs('finance')" wire:navigate>{{ __('Balance sheet') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            @endif
            @if ($me->canAny(['access.manage', 'audit.view', 'manage-whatsapp', 'deploy']))
                <flux:sidebar.group :heading="__('Admin')" class="grid">
                    @can('access.manage')
                        <flux:sidebar.item icon="shield-check" :href="route('access')" :current="request()->routeIs('access')" wire:navigate>{{ __('Admin access') }}</flux:sidebar.item>
                    @endcan
                    @can('audit.view')
                        <flux:sidebar.item icon="finger-print" :href="route('audit')" :current="request()->routeIs('audit')" wire:navigate>{{ __('Audit trail') }}</flux:sidebar.item>
                    @endcan
                    @can('manage-whatsapp')
                        @php $unreadChats = \App\Models\WhatsappMessage::where('direction', 'in')->whereNull('read_at')->whereNotNull('member_id')->count(); @endphp
                        <flux:sidebar.item icon="chat-bubble-oval-left-ellipsis" :href="route('whatsapp.chats')" :current="request()->routeIs('whatsapp.chats')" :badge="$unreadChats ?: null" wire:navigate>{{ __('Chats') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="chat-bubble-left-right" :href="route('whatsapp')" :current="request()->routeIs('whatsapp')" wire:navigate>{{ __('WhatsApp') }}</flux:sidebar.item>
                    @endcan
                    @can('deploy')
                        <flux:sidebar.item icon="rocket-launch" :href="route('deployments')" :current="request()->routeIs('deployments*')" wire:navigate>{{ __('Deployments') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            @endif
        </flux:sidebar.nav>

        <flux:spacer />


        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu -->
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @can('send-whatsapp')
        <livewire:whatsapp-share />
    @endcan

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>
