<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'preferred_run_days',
        'preferred_surface',
        'goals',
        'constraints',
        'races_json',
        'availability_json',
        'health_json',
        'equipment_json',
        'onboarding_completed',
        'hr_z1_min', 'hr_z1_max',
        'hr_z2_min', 'hr_z2_max',
        'hr_z3_min', 'hr_z3_max',
        'hr_z4_min', 'hr_z4_max',
        'hr_z5_min', 'hr_z5_max',
        // M1 beyond minimum — projection columns
        'primary_race_date',
        'primary_race_distance_km',
        'primary_race_priority',
        'max_session_min',
        'has_current_pain',
        'has_hr_sensor',
        'profile_quality_score',
    ];

    protected function casts(): array
    {
        return [
            'races_json' => 'array',
            'availability_json' => 'array',
            'health_json' => 'array',
            'equipment_json' => 'array',
            'onboarding_completed' => 'boolean',
            'primary_race_date' => 'date',
            'primary_race_distance_km' => 'decimal:2',
            'has_current_pain' => 'boolean',
            'has_hr_sensor' => 'boolean',
        ];
    }
}
