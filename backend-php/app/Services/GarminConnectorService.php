<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GarminConnectorService
{
    /**
     * @return array{ok:bool,payload:array<string,mixed>}
     */
    public function startConnect(int $userId, string $email, string $password): array
    {
        return $this->post('/v1/garmin/connect/start', [
            'userRef'  => (string) $userId,
            'email'    => $email,
            'password' => $password,
        ]);
    }

    /**
     * @return array{ok:bool,payload:array<string,mixed>}
     */
    public function sync(int $userId, ?string $fromIso, ?string $toIso): array
    {
        return $this->post('/v1/garmin/sync', [
            'userRef' => (string) $userId,
            'fromIso' => $fromIso,
            'toIso' => $toIso,
        ]);
    }

    /**
     * @return array{ok:bool,payload:array<string,mixed>}
     */
    public function status(int $userId): array
    {
        $baseUrl = rtrim((string) env('GARMIN_CONNECTOR_BASE_URL', ''), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'payload' => ['error' => 'GARMIN_CONNECTOR_NOT_CONFIGURED']];
        }
        $response = Http::timeout(20)
            ->withHeaders($this->headers())
            ->get($baseUrl . '/v1/garmin/accounts/' . $userId . '/status');
        if (!$response->successful()) {
            return ['ok' => false, 'payload' => $this->failurePayload($response)];
        }
        $json = $response->json();
        return ['ok' => true, 'payload' => is_array($json) ? $json : []];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,payload:array<string,mixed>}
     */
    private function post(string $path, array $payload): array
    {
        $baseUrl = rtrim((string) env('GARMIN_CONNECTOR_BASE_URL', ''), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'payload' => ['error' => 'GARMIN_CONNECTOR_NOT_CONFIGURED']];
        }
        $response = Http::timeout(45)
            ->withHeaders($this->headers())
            ->post($baseUrl . $path, $payload);

        if (!$response->successful()) {
            return ['ok' => false, 'payload' => $this->failurePayload($response)];
        }
        $json = $response->json();
        return ['ok' => true, 'payload' => is_array($json) ? $json : []];
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        $headers = [];
        $key = trim((string) env('GARMIN_CONNECTOR_API_KEY', ''));
        if ($key !== '') {
            $headers['x-connector-key'] = $key;
        }
        return $headers;
    }

    /**
     * @return array<string,mixed>
     */
    private function failurePayload(Response $response): array
    {
        $json = $response->json();
        if (is_array($json) && isset($json['detail']) && is_array($json['detail'])) {
            return $json['detail'];
        }
        return is_array($json) ? $json : ['error' => 'GARMIN_CONNECTOR_REQUEST_FAILED'];
    }
}
