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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            // Enforces that every single task must trace back to a valid strategic objective
            $table->foreignId('objective_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();

            // Execution Status (Pending, In_Progress, Completed, Blocked)
            $table->string('status')->default('Pending');

            // Eisenhower Mapping (Do_First, Schedule, Delegate, Eliminate)
            $table->string('eisenhower_quadrant')->default('Schedule');

            $table->integer('effort_hours')->default(1);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Optimization indices for loading the Kanban/Matrix views rapidly
            $table->index('objective_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
