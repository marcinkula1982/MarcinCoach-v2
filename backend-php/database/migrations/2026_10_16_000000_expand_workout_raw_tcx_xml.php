<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TCX exports commonly exceed MySQL TEXT's 64 KiB limit.
        Schema::table('workout_raw_tcx', fn (Blueprint $table) => $table->longText('xml')->change());
    }

    public function down(): void
    {
        // Keep the wider column: narrowing it would risk truncating stored files.
    }
};
