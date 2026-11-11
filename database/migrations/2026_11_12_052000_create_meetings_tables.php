<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Team business meetings run by the secretary (not services / ministrations).
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 20)->default('general'); // general, committee, special, prayer
            $table->date('date');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('venue')->nullable();
            $table->text('agenda')->nullable();
            $table->longText('minutes')->nullable();
            $table->string('status', 12)->default('scheduled'); // scheduled, held, cancelled
            $table->foreignId('chaired_by')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('minutes_by')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('date');
        });

        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10); // present, late, apology, absent
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'member_id']);
        });

        Schema::create('meeting_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_actions');
        Schema::dropIfExists('meeting_attendances');
        Schema::dropIfExists('meetings');
    }
};
