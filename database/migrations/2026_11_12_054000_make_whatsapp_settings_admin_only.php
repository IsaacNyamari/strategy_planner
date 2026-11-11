<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp settings and member chats are now for admins only, so the
        // grantable permission is removed from every role.
        Permission::query()->where('name', 'whatsapp.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::findOrCreate('whatsapp.manage', 'web');
    }
};
