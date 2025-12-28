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
        Schema::create('plan_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('snapshot_json'); // JSON PlanSnapshot
            $table->string('window_start_iso'); // ISO timestamp
            $table->string('window_end_iso'); // ISO timestamp
            $table->timestamp('created_at');
            
            $table->index(['user_id', 'window_start_iso']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_snapshots');
    }
};
