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
        Schema::create('plan_compliance_v2', function (Blueprint $table) {
            $table->foreignId('workout_id')->primary()->constrained()->onDelete('cascade');
            $table->integer('expected_hr_zone_min')->nullable();
            $table->integer('expected_hr_zone_max')->nullable();
            $table->integer('actual_hr_z1_sec')->nullable();
            $table->integer('actual_hr_z2_sec')->nullable();
            $table->integer('actual_hr_z3_sec')->nullable();
            $table->integer('actual_hr_z4_sec')->nullable();
            $table->integer('actual_hr_z5_sec')->nullable();
            $table->integer('high_intensity_sec')->nullable();
            $table->float('high_intensity_ratio')->nullable();
            $table->enum('status', ['OK', 'MINOR_DEVIATION', 'MAJOR_DEVIATION', 'UNKNOWN']);
            $table->timestamp('generated_at')->useCurrent();
            
            $table->index('workout_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_compliance_v2');
    }
};
