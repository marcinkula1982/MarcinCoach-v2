<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workout extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'kind',
        'summary',
        'race_meta',
        'workout_meta',
        'source',
        'source_activity_id',
        'source_user_id',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'race_meta' => 'array',
            'workout_meta' => 'array',
        ];
    }

    public function rawTcx(): HasOne
    {
        return $this->hasOne(WorkoutRawTcx::class, 'workout_id');
    }
}
