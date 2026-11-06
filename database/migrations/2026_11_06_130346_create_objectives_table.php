<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('objectives', function (Blueprint $table) {
            $table->id();
            // Strict relation linking this objective to a parental high-level strategy
            $table->foreignId('strategy_id')->constrained('strategies')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('current_progress')->default(0); // Rolled-up task execution %
            $table->string('metric_unit')->default('%'); // For tracking (%, revenue, etc.)
            $table->string('status')->default('Not_Started'); // Not_Started, In_Progress, Completed
            $table->date('target_date')->nullable();
            $table->timestamps();

            // Performance index for swift downstream calculations
            $table->index('strategy_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('objectives');
    }
};
