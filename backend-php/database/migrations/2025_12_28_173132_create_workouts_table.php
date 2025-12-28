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
        Schema::create('workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('action'); // 'save', 'preview-only'
            $table->string('kind'); // 'training', 'race'
            $table->text('summary'); // JSON WorkoutSummary
            $table->text('race_meta')->nullable(); // JSON RaceMeta
            $table->text('workout_meta')->nullable(); // JSON object
            $table->string('source')->default('MANUAL_UPLOAD');
            $table->string('source_activity_id')->nullable();
            $table->string('source_user_id')->nullable();
            $table->string('dedupe_key');
            $table->timestamps();
            
            $table->unique(['user_id', 'dedupe_key'], 'workouts_user_dedupe_unique');
            $table->index('user_id');
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'source', 'source_activity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workouts');
    }
};
