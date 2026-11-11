<?php

use App\Access\Access;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Access::sync();

        // Existing admins (the old is_admin flag) become holders of the admin role.
        if (Schema::hasColumn('users', 'is_admin')) {
            foreach (DB::table('users')->where('is_admin', true)->pluck('id') as $id) {
                User::query()->whereKey($id)->first()?->assignRole(Access::ADMIN);
            }

            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'is_active']);
            $table->boolean('is_admin')->default(false);
        });
    }
};
