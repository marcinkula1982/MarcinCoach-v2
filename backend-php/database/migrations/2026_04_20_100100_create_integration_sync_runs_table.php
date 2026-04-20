<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32); // strava | garmin
            $table->string('status', 32)->default('started'); // started | success | partial | failed
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('deduped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_runs');
    }
};
