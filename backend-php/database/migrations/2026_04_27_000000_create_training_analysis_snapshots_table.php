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
        Schema::create('training_analysis_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedSmallInteger('window_days');
            $table->string('service_version', 64);
            $table->string('cache_key', 191);
            $table->string('computed_at_iso');
            $table->longText('snapshot_json');
            $table->timestamps();

            $table->index(['user_id', 'window_days', 'created_at']);
            $table->index('cache_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_analysis_snapshots');
    }
};
