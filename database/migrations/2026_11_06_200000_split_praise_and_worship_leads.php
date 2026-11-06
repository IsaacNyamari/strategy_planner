<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Services have two lead ministers: one for the praise session and one for
 * the worship session. Members can be eligible for either or both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('can_lead_praise')->default(false)->after('part');
            $table->boolean('can_lead_worship')->default(false)->after('can_lead_praise');
        });

        // Anyone who could lead before can lead either session.
        DB::table('members')->where('can_lead', true)->update(['can_lead_praise' => true, 'can_lead_worship' => true]);

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('can_lead');
        });

        // Existing single leads become praise leaders.
        DB::table('ministration_member')->where('role', 'lead')->update(['role' => 'praise_lead']);
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('can_lead')->default(false)->after('part');
        });

        DB::table('members')->where('can_lead_praise', true)->orWhere('can_lead_worship', true)->update(['can_lead' => true]);
        DB::table('ministration_member')->where('role', 'praise_lead')->update(['role' => 'lead']);
        DB::table('ministration_member')->where('role', 'worship_lead')->update(['role' => 'backup']);

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['can_lead_praise', 'can_lead_worship']);
        });
    }
};
