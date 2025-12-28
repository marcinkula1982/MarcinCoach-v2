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
        Schema::create('training_signals_v1', function (Blueprint $table) {
            $table->foreignId('workout_id')->primary()->constrained()->onDelete('cascade');
            $table->integer('duration_sec');
            $table->integer('distance_m');
            $table->integer('avg_pace_sec_per_km')->nullable();
            $table->string('duration_bucket'); // DUR_SHORT, DUR_MEDIUM, DUR_LONG, UNKNOWN
            $table->boolean('flag_very_short');
            $table->boolean('flag_long_run');
            $table->timestamp('generated_at')->useCurrent();
            
            $table->index('duration_bucket');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_signals_v1');
    }
};
