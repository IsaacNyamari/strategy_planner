<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small key/value store for settings the team edits in the app
        // (e.g. default probation length).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Membership lifecycle: probation -> active (confirmed) / inactive.
        Schema::table('members', function (Blueprint $table) {
            $table->string('status', 12)->default('active')->after('is_active');
            $table->date('joined_at')->nullable()->after('status');
            $table->date('probation_ends_at')->nullable()->after('joined_at');
            $table->date('confirmed_at')->nullable()->after('probation_ends_at');
        });

        DB::table('members')->where('is_active', false)->update(['status' => 'inactive']);
        DB::table('members')->whereNull('joined_at')->update(['joined_at' => DB::raw('DATE(created_at)')]);

        // Behaviour notes recorded during (or after) probation.
        Schema::create('member_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('note'); // positive | concern | note
            $table->text('note');
            $table->timestamps();
        });

        // What each member owes per year.
        Schema::create('contribution_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->decimal('monthly_amount', 10, 2)->default(0);
            $table->decimal('registration_fee', 10, 2)->default(0);
            $table->timestamps();
        });

        // Arising / special contributions (uniform fund, bereavement, trip...).
        Schema::create('special_contributions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount_per_member', 10, 2)->nullable(); // expected from each member
            $table->decimal('target_amount', 12, 2)->nullable();     // overall goal
            $table->date('due_date')->nullable();
            $table->boolean('is_open')->default(true);
            $table->timestamps();
        });

        // Every shilling received, of any kind.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12); // monthly | registration | special
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month')->nullable(); // 1-12, monthly only
            $table->foreignId('special_contribution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('paid_on');
            $table->string('method', 10)->default('mpesa'); // mpesa | cash | bank
            $table->string('reference')->nullable();        // e.g. M-Pesa code
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'type', 'year']);
            $table->index(['type', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('special_contributions');
        Schema::dropIfExists('contribution_rates');
        Schema::dropIfExists('member_reviews');
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn(['status', 'joined_at', 'probation_ends_at', 'confirmed_at']));
        Schema::dropIfExists('settings');
    }
};
