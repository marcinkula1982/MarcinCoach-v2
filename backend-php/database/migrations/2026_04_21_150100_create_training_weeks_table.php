<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3/M4 beyond current scope — Etap A2.
 * training_weeks — pamięć planistyczna tygodnia:
 *  - dane planowane (planned_total_min, planned_quality_count),
 *  - dane wykonane (actual_total_min, actual_quality_count),
 *  - kontekst bloku (block_type, week_role, block_goal, key_capability_focus, load_direction),
 *  - goal_met (planned vs actual >= 80%),
 *  - decision_log (JSON: ślad decyzyjny — co wpłynęło na ten plan).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('training_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->date('week_start_date');
            $table->date('week_end_date');

            $table->string('block_type', 32)->nullable();
            $table->string('week_role', 32)->nullable();
            $table->string('block_goal', 128)->nullable();
            $table->string('key_capability_focus', 64)->nullable();
            $table->string('load_direction', 16)->nullable();

            $table->integer('planned_total_min')->nullable();
            $table->integer('actual_total_min')->nullable();
            $table->integer('planned_quality_count')->nullable();
            $table->integer('actual_quality_count')->nullable();

            $table->tinyInteger('goal_met')->nullable();
            $table->json('decision_log')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'week_start_date'], 'uq_user_week');
            $table->index(['user_id', 'week_start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_weeks');
    }
};
