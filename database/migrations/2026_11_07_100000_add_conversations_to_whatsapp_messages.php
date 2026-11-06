<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One-to-one conversations with members reuse the message log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('direction', 3)->default('out')->after('id'); // out | in
            $table->foreignId('member_id')->nullable()->after('whatsapp_target_id')->constrained()->nullOnDelete();
            $table->timestamp('read_at')->nullable()->after('status'); // incoming: read in the app
            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn(['direction', 'read_at']);
        });
    }
};
