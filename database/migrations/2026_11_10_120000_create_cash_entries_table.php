<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money that isn't a member contribution: other income (offerings,
        // donations, fundraising) and every expense, with its receipt.
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3); // in | out
            $table->string('category', 40);
            $table->string('description');
            $table->string('party')->nullable(); // received from / paid to
            $table->decimal('amount', 12, 2);
            $table->date('entry_date');
            $table->string('method', 10)->default('mpesa');
            $table->string('reference')->nullable();
            $table->foreignId('special_contribution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['entry_date', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
    }
};
