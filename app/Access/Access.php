<?php

namespace App\Access;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The team's roles and permissions in one place.
 *
 * Everyone who can log in always sees their own dashboard, their own tasks
 * and their own contributions; everything else is granted by permission.
 */
class Access
{
    public const ADMIN = 'admin';

    public const CHAIRMAN = 'chairman';

    public const MUSIC_DIRECTOR = 'music director';

    public const SECRETARY = 'secretary';

    public const TREASURER = 'treasurer';

    /** Permission => what it allows, grouped by area. */
    public const PERMISSIONS = [
        'Planning' => [
            'strategies.view' => 'See strategies and objectives',
            'strategies.manage' => 'Create and change strategies, objectives and their tasks',
            'tasks.view' => "See everyone's tasks",
            'tasks.manage' => 'Create, assign and change any task',
        ],
        'Ministry' => [
            'roster.view' => 'See the ministers roster',
            'roster.manage' => 'Generate and change the roster and meetings',
            'members.view' => 'See the members list',
            'members.manage' => 'Add and change members, probation and availability',
            'uniforms.view' => 'See uniforms and the uniform timetable',
            'uniforms.manage' => 'Change uniforms and the timetable',
            'songs.view' => 'See songs & hymns',
            'songs.manage' => 'Add, change and AI-suggest songs & hymns',
        ],
        'Money' => [
            'contributions.view' => "See everyone's contributions",
            'contributions.manage' => 'Record payments, set rates and arising contributions',
            'finance.view' => 'See the balance sheet and receipts',
            'finance.manage' => 'Record expenses and other income',
        ],
        'Meetings' => [
            'meetings.view' => 'See meetings, attendance and minutes',
            'meetings.manage' => 'Create meetings, take attendance and write minutes',
        ],
        'Communication' => [
            'whatsapp.send' => 'Send announcements to WhatsApp groups/channels',
            'posts.manage' => 'Schedule posts',
        ],
        'Administration' => [
            'access.manage' => 'Manage users, roles and permissions',
            'audit.view' => 'See the audit trail',
            'deploy.run' => 'Deploy new versions of the app',
        ],
    ];

    /**
     * Default permissions per role. Admin always gets everything.
     *
     * @return array<string, list<string>>
     */
    public static function roleDefaults(): array
    {
        $all = self::permissionNames();

        return [
            self::ADMIN => $all,
            self::CHAIRMAN => array_values(array_diff($all, ['deploy.run'])),
            self::MUSIC_DIRECTOR => ['songs.view', 'songs.manage'],
            self::SECRETARY => ['meetings.view', 'meetings.manage'],
            self::TREASURER => ['contributions.view', 'contributions.manage', 'finance.view', 'finance.manage'],
        ];
    }

    /** @return list<string> */
    public static function permissionNames(): array
    {
        return array_keys(array_merge(...array_values(self::PERMISSIONS)));
    }

    public static function describe(string $permission): string
    {
        return array_merge(...array_values(self::PERMISSIONS))[$permission] ?? $permission;
    }

    /**
     * Create missing permissions and roles. Existing roles keep the
     * permissions an admin gave them, except admin, which always has all.
     * People listed in ADMIN_EMAILS always hold the admin role.
     */
    public static function sync(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::permissionNames() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::roleDefaults() as $role => $permissions) {
            $model = Role::query()->where('name', $role)->where('guard_name', 'web')->first();
            if (! $model) {
                Role::create(['name' => $role, 'guard_name' => 'web'])->syncPermissions($permissions);
            } elseif ($role === self::ADMIN) {
                $model->syncPermissions($permissions);
            }
        }

        $admins = array_map('strtolower', (array) config('worship.admins', []));
        if ($admins !== []) {
            User::query()->whereIn('email', $admins)->get()
                ->each(fn (User $user) => $user->hasRole(self::ADMIN) || $user->assignRole(self::ADMIN));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
