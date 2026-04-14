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
        Schema::create('training_alerts_v1', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->constrained('workouts')->onDelete('cascade');
            $table->string('code'); // PLAN_MISSING, DURATION_MAJOR_OVERSHOOT, EASY_BECAME_Z5, etc.
            $table->string('severity'); // INFO, WARNING, CRITICAL
            $table->text('payload_json')->nullable(); // JSON with technical alert data (ratio, sec, zones, etc.)
            $table->timestamp('generated_at');
            
            $table->unique(['workout_id', 'code']);
            $table->index('workout_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_alerts_v1');
    }
};

