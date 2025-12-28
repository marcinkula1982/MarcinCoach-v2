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
        Schema::create('workout_import_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->constrained()->onDelete('cascade');
            $table->string('source');
            $table->string('source_activity_id')->nullable();
            $table->string('tcx_hash', 64)->nullable(); // sha256 produces 64 character hex string
            $table->string('status'); // ENUM: CREATED, UPDATED, DEDUPED
            $table->timestamp('imported_at')->useCurrent();
            
            $table->index('workout_id');
            $table->index(['source', 'source_activity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_import_events');
    }
};
