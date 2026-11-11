<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An optional audio clip (song snippet, voice announcement) sent with the post.
        Schema::table('scheduled_posts', function (Blueprint $table) {
            $table->string('audio_path')->nullable()->after('image_path');
            $table->string('audio_name')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_posts', fn (Blueprint $table) => $table->dropColumn(['audio_path', 'audio_name']));
    }
};
