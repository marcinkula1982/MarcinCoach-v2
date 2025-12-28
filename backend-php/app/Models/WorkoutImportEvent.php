<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutImportEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workout_id',
        'source',
        'source_activity_id',
        'tcx_hash',
        'status',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }
}
