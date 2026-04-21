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
            $table->json('races_json')->nullable()->after('constraints');
            $table->json('availability_json')->nullable()->after('races_json');
            $table->json('health_json')->nullable()->after('availability_json');
            $table->json('equipment_json')->nullable()->after('health_json');
            $table->boolean('onboarding_completed')->default(false)->after('equipment_json');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'races_json',
                'availability_json',
                'health_json',
                'equipment_json',
                'onboarding_completed',
            ]);
        });
    }
};

