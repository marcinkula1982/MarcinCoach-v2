<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->date('primary_race_date')->nullable()->after('onboarding_completed');
            $table->decimal('primary_race_distance_km', 6, 2)->nullable()->after('primary_race_date');
            $table->string('primary_race_priority', 1)->nullable()->after('primary_race_distance_km');
            $table->unsignedSmallInteger('max_session_min')->nullable()->after('primary_race_priority');
            $table->boolean('has_current_pain')->default(false)->after('max_session_min');
            $table->boolean('has_hr_sensor')->default(false)->after('has_current_pain');
            $table->unsignedTinyInteger('profile_quality_score')->nullable()->after('has_hr_sensor');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'primary_race_date',
                'primary_race_distance_km',
                'primary_race_priority',
                'max_session_min',
                'has_current_pain',
                'has_hr_sensor',
                'profile_quality_score',
            ]);
        });
    }
};
