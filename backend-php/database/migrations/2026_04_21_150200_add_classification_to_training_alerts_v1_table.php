<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3/M4 beyond current scope — Etap A3.
 * Rozszerzenie training_alerts_v1 o klasyfikację rodzin + confidence + kod wyjaśnienia.
 *
 * family           : safety | compliance | trend | data_quality
 * confidence       : low | medium | high
 * explanation_code : klucz i18n / słownikowy dla warstwy prezentacji
 * week_id          : FK logiczne do training_weeks dla alertów trendowych (per-week)
 *
 * Alerty dzielą się na:
 *  - per-workout : workout_id != null, week_id = null (jak dotychczas)
 *  - per-week    : workout_id = null, week_id != null (NOWE, trendowe)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('training_alerts_v1', function (Blueprint $table) {
            $table->string('family', 32)->nullable()->after('severity');
            $table->string('confidence', 16)->nullable()->default('medium')->after('family');
            $table->string('explanation_code', 64)->nullable()->after('confidence');
            $table->unsignedBigInteger('week_id')->nullable()->after('explanation_code');

            $table->index('week_id');
        });

        // Rozluźniamy workout_id do NULL — per-week alerty mają workout_id=null.
        // Laravel 11+ obsługuje ->change() na SQLite i MySQL natywnie.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            \DB::statement('ALTER TABLE training_alerts_v1 MODIFY workout_id BIGINT UNSIGNED NULL');
            try {
                \DB::statement('ALTER TABLE training_alerts_v1 ADD UNIQUE KEY uq_week_code (week_id, code)');
            } catch (\Throwable) {
                // idempotent — index może istnieć po re-run migracji manualnych
            }
        } else {
            Schema::table('training_alerts_v1', function (Blueprint $table) {
                $table->unsignedBigInteger('workout_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('training_alerts_v1', function (Blueprint $table) {
            $table->dropIndex(['week_id']);
            $table->dropColumn(['family', 'confidence', 'explanation_code', 'week_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            try {
                \DB::statement('ALTER TABLE training_alerts_v1 DROP INDEX uq_week_code');
            } catch (\Throwable) {
                // ignore
            }
            \DB::statement('ALTER TABLE training_alerts_v1 MODIFY workout_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
