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
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->integer('hr_z1_min')->nullable();
            $table->integer('hr_z1_max')->nullable();
            $table->integer('hr_z2_min')->nullable();
            $table->integer('hr_z2_max')->nullable();
            $table->integer('hr_z3_min')->nullable();
            $table->integer('hr_z3_max')->nullable();
            $table->integer('hr_z4_min')->nullable();
            $table->integer('hr_z4_max')->nullable();
            $table->integer('hr_z5_min')->nullable();
            $table->integer('hr_z5_max')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'hr_z1_min',
                'hr_z1_max',
                'hr_z2_min',
                'hr_z2_max',
                'hr_z3_min',
                'hr_z3_max',
                'hr_z4_min',
                'hr_z4_max',
                'hr_z5_min',
                'hr_z5_max',
            ]);
        });
    }
};
