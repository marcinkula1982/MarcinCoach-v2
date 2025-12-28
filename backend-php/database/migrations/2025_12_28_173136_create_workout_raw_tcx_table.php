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
        Schema::create('workout_raw_tcx', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->unique()->constrained()->onDelete('cascade');
            $table->text('xml');
            $table->timestamp('created_at');
            
            $table->index('workout_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_raw_tcx');
    }
};
