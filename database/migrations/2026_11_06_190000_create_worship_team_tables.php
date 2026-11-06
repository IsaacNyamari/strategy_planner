<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Praise & worship team members (not every member needs a login).
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('gender', 10); // male | female (drives uniform bottoms)
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('part')->nullable(); // Soprano, Alto, Tenor, Keys, Drums...
            $table->boolean('can_lead')->default(false); // eligible to be the lead minister
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('member_unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'date']);
        });

        // Uniform catalogue: top + ladies' dress/skirt + men's trousers.
        Schema::create('uniforms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('top');
            $table->string('ladies_bottom');
            $table->string('men_bottom');
            $table->string('colors')->nullable();
            $table->text('notes')->nullable();
            $table->string('source')->default('manual'); // manual | ai
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A scheduled Sunday / midweek service, or an abrupt/special meeting.
        Schema::create('ministrations', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('type'); // sunday | wednesday | friday | special
            $table->string('title')->nullable();
            $table->foreignId('uniform_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['date', 'starts_at']);
        });

        Schema::create('ministration_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ministration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('backup'); // lead | backup
            $table->timestamps();

            $table->unique(['ministration_id', 'member_id']);
        });

        // Song / hymn library (English & Swahili).
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('language', 10); // english | swahili
            $table->string('category', 10); // praise | worship | hymn
            $table->string('artist')->nullable(); // artist / hymn writer
            $table->string('musical_key', 10)->nullable(); // e.g. G, Bb, F#m
            $table->unsignedSmallInteger('tempo_bpm')->nullable();
            $table->string('time_signature', 5)->nullable(); // 4/4, 3/4, 6/8
            $table->string('theme')->nullable();
            $table->longText('lyrics')->nullable();
            $table->boolean('is_public_domain')->default(false);
            $table->text('notes')->nullable();
            $table->string('source')->default('manual'); // manual | ai
            $table->timestamps();

            $table->index(['language', 'category']);
        });

        // Setlist for a service / meeting.
        Schema::create('ministration_song', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ministration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['ministration_id', 'song_id']);
        });

        Schema::table('strategies', function (Blueprint $table) {
            $table->string('horizon')->default('long_term')->after('description'); // long_term | short_term
        });

        Schema::table('objectives', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('strategy_id')->constrained('members')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('objective_id')->constrained('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('assignee_id'));
        Schema::table('objectives', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_id'));
        Schema::table('strategies', fn (Blueprint $table) => $table->dropColumn('horizon'));
        Schema::dropIfExists('ministration_song');
        Schema::dropIfExists('songs');
        Schema::dropIfExists('ministration_member');
        Schema::dropIfExists('ministrations');
        Schema::dropIfExists('uniforms');
        Schema::dropIfExists('member_unavailabilities');
        Schema::dropIfExists('members');
    }
};
