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
        Schema::create('plan_compliance_v1', function (Blueprint $table) {
            $table->foreignId('workout_id')->primary()->constrained()->onDelete('cascade');
            $table->integer('expected_duration_sec')->nullable();
            $table->integer('actual_duration_sec');
            $table->integer('delta_duration_sec')->nullable();
            $table->float('duration_ratio')->nullable();
            $table->enum('status', ['OK', 'MINOR_DEVIATION', 'MAJOR_DEVIATION', 'UNKNOWN']);
            $table->boolean('flag_overshoot_duration');
            $table->boolean('flag_undershoot_duration');
            $table->timestamp('generated_at')->useCurrent();
            
            $table->index('workout_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_compliance_v1');
    }
};
