<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users')] class extends Component {
    public string $name = '';
    public string $email = '';
    public bool $isAdmin = false;

    #[Computed]
    public function users()
    {
        return User::orderByDesc('is_admin')->orderBy('name')->get();
    }

    /**
     * Create an account. The person gets an email link to set their own
     * password; nobody ever types or sees it here.
     */
    public function create(): void
    {
        $this->authorize('manage-users');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => strtolower($this->email),
            'password' => Str::password(40), // replaced when they set their own
            'is_admin' => $this->isAdmin,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save(); // created by an admin

        $sent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;

        $this->reset('name', 'email', 'isAdmin');
        Flux::modal('new-user')->close();
        Flux::toast(
            variant: $sent ? 'success' : 'warning',
            text: $sent ? "Account created. {$user->name} will get an email to set a password." : "Account created, but the email could not be sent. They can use “Forgot password” on the login page.",
        );
        unset($this->users);
    }

    public function toggleAdmin(int $id): void
    {
        $this->authorize('manage-users');
        $user = User::findOrFail($id);

        if ($user->is(auth()->user())) {
            Flux::toast(variant: 'warning', text: "You can't change your own admin rights.");

            return;
        }

        $user->update(['is_admin' => ! $user->is_admin]);
        unset($this->users);
    }

    public function resendLink(int $id): void
    {
        $this->authorize('manage-users');
        $user = User::findOrFail($id);
        $status = Password::sendResetLink(['email' => $user->email]);

        $status === Password::RESET_LINK_SENT
            ? Flux::toast(variant: 'success', text: "Password link sent to {$user->email}.")
            : Flux::toast(variant: 'warning', text: __($status));
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-users');
        $user = User::findOrFail($id);

        if ($user->is(auth()->user())) {
            Flux::toast(variant: 'warning', text: "You can't remove your own account here.");

            return;
        }

        $user->delete();
        unset($this->users);
        Flux::toast(text: "{$user->name} removed.");
    }

    public function permanentAdmins(): array
    {
        return config('worship.admins', []);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Users</flux:heading>
            <flux:subheading>Who can log in. Public sign-up is closed, so create accounts here.</flux:subheading>
        </div>
        <flux:modal.trigger name="new-user">
            <flux:button variant="primary" icon="user-plus">Add user</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:card class="divide-y divide-zinc-100 p-0! dark:divide-zinc-700">
        @foreach ($this->users as $u)
            @php $permanent = in_array(strtolower($u->email), $this->permanentAdmins(), true); @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="user-{{ $u->id }}">
                <div class="flex min-w-0 items-center gap-3">
                    <flux:avatar size="sm" :name="$u->name" :initials="$u->initials()" color="auto" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="truncate text-sm font-medium">{{ $u->name }}</span>
                            @if ($u->isAdmin())<flux:badge size="sm" color="indigo" icon="shield-check">Admin</flux:badge>@endif
                            @if ($u->is(auth()->user()))<flux:badge size="sm">You</flux:badge>@endif
                        </div>
                        <div class="truncate text-xs text-zinc-500">{{ $u->email }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    @if ($permanent)
                        <flux:text size="sm" class="me-2" title="Set in the server's ADMIN_EMAILS">permanent admin</flux:text>
                    @elseif (! $u->is(auth()->user()))
                        <flux:button size="xs" :variant="$u->is_admin ? 'filled' : 'ghost'" icon="shield-check" wire:click="toggleAdmin({{ $u->id }})">{{ $u->is_admin ? 'Remove admin' : 'Make admin' }}</flux:button>
                    @endif
                    <flux:button size="xs" variant="ghost" icon="envelope" wire:click="resendLink({{ $u->id }})" title="Email a link to set or reset their password">Password link</flux:button>
                    @unless ($u->is(auth()->user()) || $permanent)
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $u->id }})" wire:confirm="Remove {{ $u->name }}'s account? They will no longer be able to log in." aria-label="Remove" />
                    @endunless
                </div>
            </div>
        @endforeach
    </flux:card>

    <flux:text size="sm">Admins can add users, manage WhatsApp and see one-to-one chats. Deployments stay limited to the emails set on the server.</flux:text>

    <flux:modal name="new-user" class="md:w-md">
        <form wire:submit="create" class="space-y-5">
            <div>
                <flux:heading size="lg">Add user</flux:heading>
                <flux:text class="mt-1">They'll get an email with a link to set their own password.</flux:text>
            </div>
            <flux:input wire:model="name" label="Full name" />
            <flux:input wire:model="email" type="email" label="Email" />
            <flux:switch wire:model="isAdmin" label="Admin" description="Can add users, manage WhatsApp and read chats." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Create account</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
