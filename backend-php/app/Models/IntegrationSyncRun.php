<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSyncRun extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'status',
        'fetched_count',
        'imported_count',
        'deduped_count',
        'failed_count',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
