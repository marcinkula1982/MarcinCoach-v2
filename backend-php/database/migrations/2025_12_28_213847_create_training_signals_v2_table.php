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
        Schema::create('training_signals_v2', function (Blueprint $table) {
            $table->foreignId('workout_id')->primary()->constrained()->onDelete('cascade');
            $table->boolean('hr_available');
            $table->integer('hr_avg_bpm')->nullable();
            $table->integer('hr_max_bpm')->nullable();
            $table->integer('hr_z1_sec')->nullable();
            $table->integer('hr_z2_sec')->nullable();
            $table->integer('hr_z3_sec')->nullable();
            $table->integer('hr_z4_sec')->nullable();
            $table->integer('hr_z5_sec')->nullable();
            $table->timestamp('generated_at')->useCurrent();
            
            $table->index('workout_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_signals_v2');
    }
};
