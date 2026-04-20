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
        'hr_z1_min', 'hr_z1_max',
        'hr_z2_min', 'hr_z2_max',
        'hr_z3_min', 'hr_z3_max',
        'hr_z4_min', 'hr_z4_max',
        'hr_z5_min', 'hr_z5_max',
    ];
}
