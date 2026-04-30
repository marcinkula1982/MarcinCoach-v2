<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('workout_id')->nullable()->constrained()->nullOnDelete();

            $table->date('planned_session_date');
            $table->string('planned_session_id')->nullable();
            $table->string('checkin_key');
            $table->string('status', 16);
            $table->string('plan_compliance', 16)->nullable();

            $table->string('planned_type', 64)->nullable();
            $table->unsignedSmallInteger('planned_duration_min')->nullable();
            $table->string('planned_intensity', 64)->nullable();
            $table->json('planned_payload')->nullable();

            $table->unsignedSmallInteger('actual_duration_min')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedTinyInteger('rpe')->nullable();
            $table->string('mood', 64)->nullable();
            $table->boolean('pain_flag')->default(false);
            $table->text('pain_note')->nullable();
            $table->text('note')->nullable();
            $table->string('skip_reason', 128)->nullable();
            $table->text('modification_reason')->nullable();
            $table->json('plan_modifications')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'checkin_key'], 'manual_check_ins_user_key_unique');
            $table->index(['user_id', 'planned_session_date', 'status'], 'manual_check_ins_user_date_status_idx');
            $table->index('workout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_check_ins');
    }
};
