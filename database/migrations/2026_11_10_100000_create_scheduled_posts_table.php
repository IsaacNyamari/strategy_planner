<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->string('image_path')->nullable(); // on the public disk
            $table->json('whatsapp_target_ids')->nullable();
            $table->boolean('to_facebook')->default(false);
            $table->timestamp('scheduled_at')->index(); // stored in UTC
            $table->string('repeat', 10)->default('none'); // none | weekly | monthly
            $table->string('status', 12)->default('scheduled'); // scheduled | sending | sent | partial | failed | cancelled
            $table->json('results')->nullable(); // per destination: name, ok, error
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
