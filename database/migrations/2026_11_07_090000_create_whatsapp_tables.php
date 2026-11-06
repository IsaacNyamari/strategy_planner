<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Groups / channels the team sends to (picked from the linked number's lists).
        Schema::create('whatsapp_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('chat_id')->unique(); // ...@g.us (group) or ...@newsletter (channel)
            $table->string('type', 10);          // group | channel
            $table->boolean('is_default')->default(false); // pre-ticked when sharing
            $table->timestamps();
        });

        // Every message sent, with its delivery status (updated by WAHA webhooks).
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_target_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chat_id');
            $table->string('target_name');
            $table->string('kind', 30);   // roster, meeting, uniforms, arising, contributions, test
            $table->text('body');
            $table->string('attachment')->nullable(); // file name of an attached PDF
            $table->string('status', 12); // sent | delivered | read | failed
            $table->string('waha_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_targets');
    }
};
