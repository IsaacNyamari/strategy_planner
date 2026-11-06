<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store every phone number in international format (+2547...) and make
 * sure no two members share a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];

        foreach (DB::table('members')->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $row) {
            $normalized = Phone::normalize($row->phone);

            // Unusable or duplicate numbers are cleared so the team re-enters them.
            if (! $normalized || isset($seen[$normalized])) {
                DB::table('members')->where('id', $row->id)->update(['phone' => null]);

                continue;
            }

            $seen[$normalized] = true;
            DB::table('members')->where('id', $row->id)->update(['phone' => $normalized]);
        }

        Schema::table('members', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }
};
