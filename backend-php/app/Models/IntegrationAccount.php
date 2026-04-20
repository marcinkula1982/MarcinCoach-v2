<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'external_user_id',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'status',
        'meta',
        'last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'access_token_expires_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }
}
