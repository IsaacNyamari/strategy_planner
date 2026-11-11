<?php

use App\Access\Access;
use App\Audit\Audit;
use App\Livewire\Guard;
use App\Models\Member;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Admin access'), Guard('access.manage')] class extends Component {
    #[Url]
    public string $tab = 'users';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    // User form
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    /** @var list<string> */
    public array $roles = [];
    public ?int $memberId = null;

    // Roles tab
    public ?int $roleId = null;
    /** @var list<string> */
    public array $rolePermissions = [];
    public string $newRoleName = '';

    #[Computed]
    public function users()
    {
        return User::with(['roles:id,name', 'member:id,name,user_id'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'off', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->get();
    }

    #[Computed]
    public function allRoles()
    {
        return Role::withCount(['permissions', 'users'])->orderBy('name')->get();
    }

    #[Computed]
    public function freeMembers()
    {
        return Member::query()->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $this->editingId))->orderBy('name')->get(['id', 'name']);
    }

    public function mount(): void
    {
        $this->roleId = Role::where('name', Access::CHAIRMAN)->value('id') ?? Role::value('id');
        $this->loadRole();
    }

    // ---- Users ---------------------------------------------------------

    public function newUser(): void
    {
        $this->resetErrorBag();
        $this->reset(['editingId', 'name', 'email', 'roles', 'memberId']);
        Flux::modal('user-form')->show();
    }

    public function editUser(int $id): void
    {
        $user = User::with('member')->findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->roles = $user->getRoleNames()->all();
        $this->memberId = $user->member?->id;
        Flux::modal('user-form')->show();
    }

    /**
     * Create or update an account. New people get an email link to set their
     * own password; nobody ever types or sees it here.
     */
    public function saveUser(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'memberId' => ['nullable', 'exists:members,id'],
        ]);

        $user = $this->editingId ? User::findOrFail($this->editingId) : null;

        if ($user?->is(auth()->user()) && in_array(Access::ADMIN, $user->getRoleNames()->all(), true) && ! in_array(Access::ADMIN, $data['roles'], true)) {
            $this->addError('roles', "You can't remove your own admin role.");

            return;
        }

        $sent = null;
        if (! $user) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Str::password(40)]);
            $user->forceFill(['email_verified_at' => now()])->save(); // created by an admin
            $sent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        } else {
            $user->update(['name' => $data['name'], 'email' => strtolower($data['email'])]);
        }

        $before = $user->getRoleNames()->sort()->values()->all();
        $user->syncRoles($data['roles']);
        $after = collect($data['roles'])->sort()->values()->all();
        if ($before !== $after) {
            Audit::record('roles_changed', "Changed roles of {$user->name}", $user, ['roles' => ['old' => implode(', ', $before) ?: 'none', 'new' => implode(', ', $after) ?: 'none']]);
        }

        // Link the login to a team member (their tasks and contributions).
        Member::where('user_id', $user->id)->where('id', '!=', $data['memberId'])->update(['user_id' => null]);
        if ($data['memberId']) {
            Member::whereKey($data['memberId'])->update(['user_id' => $user->id]);
        }

        Flux::modal('user-form')->close();
        Flux::toast(
            variant: $sent === false ? 'warning' : 'success',
            text: match ($sent) {
                true => "Account created. {$user->name} will get an email to set a password.",
                false => 'Account created, but the email could not be sent. They can use “Forgot password” on the login page.',
                null => 'Saved.',
            },
        );
        unset($this->users, $this->allRoles);
    }

    public function toggleActive(int $id): void
    {
        $user = User::findOrFail($id);
        if ($user->is(auth()->user())) {
            Flux::toast(variant: 'warning', text: "You can't switch off your own account.");

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
        unset($this->users);
    }

    public function resendLink(int $id): void
    {
        $user = User::findOrFail($id);
        $status = Password::sendResetLink(['email' => $user->email]);

        $status === Password::RESET_LINK_SENT
            ? Flux::toast(variant: 'success', text: "Password link sent to {$user->email}.")
            : Flux::toast(variant: 'warning', text: __($status));
    }

    public function deleteUser(int $id): void
    {
        $user = User::findOrFail($id);
        if ($user->is(auth()->user()) || $user->isPermanentAdmin()) {
            Flux::toast(variant: 'warning', text: "This account can't be removed here.");

            return;
        }

        $user->delete();
        unset($this->users);
        Flux::toast(text: "{$user->name} removed.");
    }

    // ---- Roles ---------------------------------------------------------

    public function selectRole(int $id): void
    {
        $this->roleId = $id;
        $this->loadRole();
    }

    protected function loadRole(): void
    {
        $role = $this->roleId ? Role::find($this->roleId) : null;
        $this->rolePermissions = $role ? $role->permissions->pluck('name')->all() : [];
    }

    public function toggleGroup(string $group): void
    {
        $names = array_keys(Access::PERMISSIONS[$group] ?? []);
        $this->rolePermissions = array_values(count(array_intersect($names, $this->rolePermissions)) === count($names)
            ? array_diff($this->rolePermissions, $names)
            : array_unique([...$this->rolePermissions, ...$names]));
    }

    public function saveRole(): void
    {
        $role = Role::findOrFail($this->roleId);
        if ($role->name === Access::ADMIN) {
            Flux::toast(variant: 'warning', text: 'The admin role always has every permission.');
            $this->loadRole();

            return;
        }

        $valid = array_values(array_intersect($this->rolePermissions, Access::permissionNames()));
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($valid);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $added = array_diff($valid, $before);
        $removed = array_diff($before, $valid);
        if ($added || $removed) {
            Audit::record('permissions_changed', 'Changed permissions of role “'.$role->name.'”', null, [
                'added' => ['old' => null, 'new' => implode(', ', $added) ?: '—'],
                'removed' => ['old' => implode(', ', $removed) ?: '—', 'new' => null],
            ]);
        }

        Flux::toast(variant: 'success', text: ucwords($role->name).' saved.');
        unset($this->allRoles);
    }

    public function createRole(): void
    {
        $this->validate(['newRoleName' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z ]+$/', Rule::unique('roles', 'name')]], [], ['newRoleName' => 'role name']);
        $role = Role::create(['name' => strtolower(trim($this->newRoleName)), 'guard_name' => 'web']);
        Audit::record('role_created', 'Created role “'.$role->name.'”');
        $this->reset('newRoleName');
        Flux::modal('new-role')->close();
        unset($this->allRoles);
        $this->selectRole($role->id);
    }

    public function deleteRole(int $id): void
    {
        $role = Role::withCount('users')->findOrFail($id);
        if (array_key_exists($role->name, Access::roleDefaults()) || $role->users_count > 0) {
            Flux::toast(variant: 'warning', text: 'Built-in roles and roles that people still hold cannot be deleted.');

            return;
        }

        Audit::record('role_deleted', 'Deleted role “'.$role->name.'”');
        $role->delete();
        unset($this->allRoles);
        $this->roleId = Role::value('id');
        $this->loadRole();
    }
}; ?>

@php
    $currentRole = $this->allRoles->firstWhere('id', $roleId);
    $isAdminRole = $currentRole?->name === \App\Access\Access::ADMIN;
@endphp

<div class="flex flex-col gap-6">
    <div>
        <flux:badge size="sm" color="indigo" icon="shield-check" class="mb-2">Admin</flux:badge>
        <flux:heading size="xl" level="1">Admin access</flux:heading>
        <flux:subheading>Who can log in, what each role can do, and who holds which role. Hidden buttons are not the only protection — the server checks every action.</flux:subheading>
    </div>

    <div class="flex gap-1">
        <flux:button size="sm" :variant="$tab === 'users' ? 'primary' : 'ghost'" wire:click="$set('tab', 'users')">Users</flux:button>
        <flux:button size="sm" :variant="$tab === 'roles' ? 'primary' : 'ghost'" wire:click="$set('tab', 'roles')">Roles & permissions</flux:button>
        @can('audit.view')
            <flux:button size="sm" variant="ghost" :href="route('audit')" wire:navigate icon:trailing="arrow-up-right">Audit trail</flux:button>
        @endcan
    </div>

    @if ($tab === 'users')
        <flux:card class="flex flex-wrap items-end gap-4">
            <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Name or email" icon="magnifying-glass" class="max-w-xs" />
            <flux:select wire:model.live="status" label="Status" class="max-w-40">
                <flux:select.option value="">All</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="off">Switched off</flux:select.option>
            </flux:select>
            <flux:spacer />
            <flux:button variant="primary" icon="user-plus" wire:click="newUser">New user</flux:button>
        </flux:card>

        <flux:card class="p-0!">
            <flux:table class="[&_th:first-child]:ps-5 [&_td:first-child]:ps-5 [&_th:last-child]:pe-5 [&_td:last-child]:pe-5">
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Roles</flux:table.column>
                    <flux:table.column>Team member</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Last login</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->users as $u)
                        <flux:table.row wire:key="user-{{ $u->id }}">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $u->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $u->email }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($u->roles as $r)
                                        <flux:badge size="sm" :color="$r->name === 'admin' ? 'indigo' : 'zinc'">{{ ucwords($r->name) }}</flux:badge>
                                    @empty
                                        <span class="text-sm text-zinc-400">No roles</span>
                                    @endforelse
                                    @if ($u->isPermanentAdmin())
                                        <flux:tooltip content="Listed in ADMIN_EMAILS on the server: always an admin."><flux:badge size="sm" color="indigo" icon="lock-closed">Owner</flux:badge></flux:tooltip>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="text-sm">{{ $u->member->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$u->is_active ? 'green' : 'red'">{{ $u->is_active ? 'Active' : 'Off' }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap text-sm">{{ $u->last_login_at?->timezone(config('worship.timezone'))->format('j M Y, H:i') ?? 'Never' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="editUser({{ $u->id }})">Edit roles & details</flux:menu.item>
                                        <flux:menu.item icon="envelope" wire:click="resendLink({{ $u->id }})">Send password link</flux:menu.item>
                                        @unless ($u->is(auth()->user()))
                                            <flux:menu.item :icon="$u->is_active ? 'no-symbol' : 'check-circle'" wire:click="toggleActive({{ $u->id }})">{{ $u->is_active ? 'Switch off' : 'Switch on' }}</flux:menu.item>
                                            @unless ($u->isPermanentAdmin())
                                                <flux:menu.separator />
                                                <flux:menu.item icon="trash" variant="danger" wire:click="deleteUser({{ $u->id }})" wire:confirm="Remove {{ $u->name }}? They will no longer be able to log in.">Delete</flux:menu.item>
                                            @endunless
                                        @endunless
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="6" class="text-center text-zinc-500">No users found.</flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @else
        <div class="grid gap-4 lg:grid-cols-[18rem_1fr]">
            <div class="space-y-3">
                <flux:modal.trigger name="new-role">
                    <flux:button variant="primary" icon="plus" class="w-full">New role</flux:button>
                </flux:modal.trigger>
                <flux:card class="divide-y divide-zinc-100 p-0! dark:divide-zinc-700">
                    @foreach ($this->allRoles as $r)
                        <button type="button" wire:key="role-{{ $r->id }}" wire:click="selectRole({{ $r->id }})" @class(['block w-full px-4 py-3 text-start', 'bg-indigo-50 dark:bg-indigo-500/10' => $r->id === $roleId])>
                            <div class="font-medium text-zinc-800 dark:text-white">{{ ucwords($r->name) }}</div>
                            <div class="text-xs text-zinc-500">{{ $r->permissions_count }} permissions · {{ $r->users_count }} {{ Str::plural('user', $r->users_count) }}</div>
                        </button>
                    @endforeach
                </flux:card>
            </div>

            @if ($currentRole)
                <flux:card class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <flux:heading size="lg">{{ ucwords($currentRole->name) }}</flux:heading>
                            <flux:text size="sm">{{ $currentRole->users_count }} {{ Str::plural('user', $currentRole->users_count) }} hold this role · {{ count($rolePermissions) }} permissions{{ $isAdminRole ? ' · admin always has everything' : '' }}</flux:text>
                        </div>
                        <div class="flex gap-2">
                            @unless (array_key_exists($currentRole->name, \App\Access\Access::roleDefaults()))
                                <flux:button variant="ghost" icon="trash" wire:click="deleteRole({{ $currentRole->id }})" wire:confirm="Delete this role?">Delete</flux:button>
                            @endunless
                            <flux:button variant="primary" wire:click="saveRole" :disabled="$isAdminRole">Save</flux:button>
                        </div>
                    </div>

                    <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach (\App\Access\Access::PERMISSIONS as $group => $perms)
                            @php $on = count(array_intersect(array_keys($perms), $rolePermissions)); @endphp
                            <div class="space-y-2 p-4" wire:key="group-{{ Str::slug($group) }}">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" class="rounded" @checked($on === count($perms)) @disabled($isAdminRole) wire:click="toggleGroup('{{ $group }}')" />
                                    <span class="font-semibold text-zinc-800 dark:text-white">{{ $group }}</span>
                                    <span class="text-xs text-zinc-400">{{ $on }}/{{ count($perms) }}</span>
                                </label>
                                <div class="grid gap-2 ps-6 sm:grid-cols-2">
                                    @foreach ($perms as $perm => $label)
                                        <label class="flex items-start gap-2 text-sm">
                                            <input type="checkbox" class="mt-0.5 rounded" value="{{ $perm }}" wire:model="rolePermissions" @disabled($isAdminRole) />
                                            <span><span class="font-mono text-xs text-zinc-500">{{ $perm }}</span><br>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @endif
        </div>
    @endif

    <flux:modal name="user-form" class="w-full max-w-lg">
        <form wire:submit="saveUser" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit user' : 'New user' }}</flux:heading>
            @unless ($editingId)
                <flux:text size="sm">They'll get an email with a link to set their own password.</flux:text>
            @endunless
            <flux:input wire:model="name" label="Name" />
            <flux:input wire:model="email" type="email" label="Email" />
            <flux:checkbox.group wire:model="roles" label="Roles" description="Everyone always sees their own dashboard, tasks and contributions.">
                @foreach ($this->allRoles as $r)
                    <flux:checkbox value="{{ $r->name }}" :label="ucwords($r->name)" />
                @endforeach
            </flux:checkbox.group>
            <flux:select wire:model="memberId" label="Team member" description="Links this login to a member so they see their own tasks and contributions.">
                <flux:select.option value="">Not a team member</flux:select.option>
                @foreach ($this->freeMembers as $m)
                    <flux:select.option value="{{ $m->id }}">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="new-role" class="w-full max-w-sm">
        <form wire:submit="createRole" class="space-y-4">
            <flux:heading size="lg">New role</flux:heading>
            <flux:input wire:model="newRoleName" label="Role name" placeholder="e.g. vice chairman" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Create</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
