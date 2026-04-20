<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AiObservabilityService
{
    /**
     * @param array<string,mixed> $context
     */
    public function warn(string $event, array $context = []): void
    {
        Log::warning('ai.' . $event, $context);
    }
}
