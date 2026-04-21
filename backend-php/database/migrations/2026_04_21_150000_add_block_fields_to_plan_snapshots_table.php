<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3/M4 beyond current scope — Etap A1.
 * Rozszerzenie plan_snapshots o metadane bloku treningowego.
 *
 * block_type           : base | build | peak | taper | recovery | return
 * week_role            : build | peak | recovery | taper | test
 * load_direction       : increase | maintain | decrease
 * key_capability_focus : aerobic_base | threshold | vo2max | long_run | economy
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plan_snapshots', function (Blueprint $table) {
            $table->string('block_type', 32)->nullable()->after('window_end_iso');
            $table->string('block_goal', 128)->nullable()->after('block_type');
            $table->string('week_role', 32)->nullable()->after('block_goal');
            $table->string('load_direction', 16)->nullable()->after('week_role');
            $table->string('key_capability_focus', 64)->nullable()->after('load_direction');
        });
    }

    public function down(): void
    {
        Schema::table('plan_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'block_type',
                'block_goal',
                'week_role',
                'load_direction',
                'key_capability_focus',
            ]);
        });
    }
};
