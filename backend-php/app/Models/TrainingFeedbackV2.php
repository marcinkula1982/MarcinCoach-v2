<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingFeedbackV2 extends Model
{
    protected $table = 'training_feedback_v2';

    protected $fillable = [
        'workout_id',
        'user_id',
        'feedback',
    ];

    protected function casts(): array
    {
        return [
            'feedback' => 'array',
        ];
    }

    public function workout()
    {
        return $this->belongsTo(Workout::class, 'workout_id');
    }
}
