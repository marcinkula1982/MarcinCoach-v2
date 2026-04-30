<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualCheckIn extends Model
{
    protected $fillable = [
        'user_id',
        'workout_id',
        'planned_session_date',
        'planned_session_id',
        'checkin_key',
        'status',
        'plan_compliance',
        'planned_type',
        'planned_duration_min',
        'planned_intensity',
        'planned_payload',
        'actual_duration_min',
        'distance_m',
        'rpe',
        'mood',
        'pain_flag',
        'pain_note',
        'note',
        'skip_reason',
        'modification_reason',
        'plan_modifications',
    ];

    protected function casts(): array
    {
        return [
            'planned_session_date' => 'date',
            'planned_payload' => 'array',
            'plan_modifications' => 'array',
            'pain_flag' => 'boolean',
            'planned_duration_min' => 'integer',
            'actual_duration_min' => 'integer',
            'distance_m' => 'integer',
            'rpe' => 'integer',
        ];
    }

    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }
}
