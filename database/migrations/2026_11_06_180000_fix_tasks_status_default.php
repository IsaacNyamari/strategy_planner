<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original default ('Pending') is not a valid TasksEnum value and
     * crashes the model cast, so switch it to 'Not_Started'.
     */
    public function up(): void
    {
        DB::table('tasks')->where('status', 'Pending')->update(['status' => 'Not_Started']);

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status')->default('Not_Started')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status')->default('Pending')->change();
        });
    }
};
