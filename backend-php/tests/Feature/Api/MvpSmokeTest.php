<?php

namespace Tests\Feature\Api;

use App\Support\WorkoutSourceContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvpSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_mvp_loop_smoke_uses_manual_check_in_without_file(): void
    {
        config(['cache.default' => 'array']);
        Carbon::setTestNow(Carbon::parse('2026-04-30T09:00:00Z'));

        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->getJson('/api/health')
                ->assertOk()
                ->assertJsonPath('status', 'ok')
                ->assertJsonStructure(['timestamp']);

            $username = 'ep010-smoke';
            $password = 'password123';

            $register = $this->postJson('/api/auth/register', [
                'username' => $username,
                'email' => 'ep010-smoke@example.com',
                'password' => $password,
            ]);
            $register->assertOk()
                ->assertJsonStructure(['sessionToken', 'username'])
                ->assertJsonPath('username', $username);

            $login = $this->postJson('/api/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);
            $login->assertOk()
                ->assertJsonStructure(['sessionToken', 'username'])
                ->assertJsonPath('username', $username);

            $headers = $this->authHeaders($username, (string) $login->json('sessionToken'));

            $this->withHeaders($headers)
                ->getJson('/api/me')
                ->assertOk()
                ->assertJsonPath('username', $username);

            $this->withHeaders($headers)
                ->getJson('/api/me/profile')
                ->assertOk()
                ->assertJsonPath('onboardingCompleted', false);

            $this->withHeaders($headers)
                ->putJson('/api/me/profile', [
                    'goals' => 'Manual MVP smoke without a workout file',
                    'races' => [[
                        'name' => 'Smoke 10K',
                        'date' => '2026-09-20',
                        'distanceKm' => 10.0,
                        'priority' => 'A',
                        'targetTime' => '00:50:00',
                    ]],
                    'availability' => [
                        'runningDays' => ['mon', 'wed', 'fri', 'sun'],
                        'requestedTrainingDays' => 4,
                        'maxSessionMin' => 60,
                        'unavailableDays' => ['sat'],
                    ],
                    'health' => [
                        'currentPain' => false,
                        'injuryHistory' => [],
                    ],
                    'equipment' => [
                        'watch' => false,
                        'hrSensor' => false,
                    ],
                    'constraints' => json_encode([
                        'onboarding' => [
                            'source' => 'manual',
                            'uploadedWorkoutsCount' => 0,
                            'confidenceHint' => 'low',
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                ])
                ->assertOk()
                ->assertJsonPath('onboardingCompleted', true)
                ->assertJsonPath('equipment.hrSensor', false);

            $initialPlan = $this->withHeaders($headers)
                ->getJson('/api/rolling-plan?days=14');
            $initialPlan->assertOk()
                ->assertJsonPath('windowDays', 14)
                ->assertJsonStructure([
                    'sessions',
                    'summary',
                    'decisionTrace',
                    'nextSession',
                ]);

            $plannedSession = $this->firstRunnableSession($initialPlan->json('sessions'));
            $this->assertNotNull($plannedSession, 'Expected a runnable session in the initial rolling plan.');

            $plannedDate = (string) $plannedSession['dateIso'];
            $plannedDuration = max(1, (int) ($plannedSession['durationMin'] ?? 0));
            $plannedSessionId = (string) ($plannedSession['id'] ?? sprintf(
                'ep010-%s-%s',
                $plannedDate,
                (string) ($plannedSession['type'] ?? 'run'),
            ));

            $checkIn = $this->withHeaders($headers)
                ->postJson('/api/workouts/manual-check-in', [
                    'plannedSessionDate' => $plannedDate,
                    'plannedSessionId' => $plannedSessionId,
                    'status' => 'done',
                    'plannedSession' => $plannedSession,
                    'plannedType' => $plannedSession['type'] ?? null,
                    'plannedDurationMin' => $plannedDuration,
                    'plannedIntensity' => $plannedSession['intensityHint'] ?? null,
                    'durationMin' => $plannedDuration,
                    'rpe' => 5,
                    'mood' => 'ok',
                    'painFlag' => false,
                    'note' => 'EP-010 smoke: manual check-in without file.',
                ]);
            $checkIn->assertCreated()
                ->assertJsonPath('created', true)
                ->assertJsonPath('checkIn.status', 'done');

            $workoutId = (int) $checkIn->json('checkIn.workoutId');
            $this->assertGreaterThan(0, $workoutId);
            $this->assertDatabaseHas('workouts', [
                'id' => $workoutId,
                'source' => WorkoutSourceContract::MANUAL_CHECK_IN,
            ]);
            $this->assertDatabaseCount('workout_raw_tcx', 0);

            $this->withHeaders($headers)
                ->getJson('/api/workouts')
                ->assertOk()
                ->assertJsonFragment(['id' => $workoutId]);

            $feedback = $this->withHeaders($headers)
                ->postJson("/api/workouts/{$workoutId}/feedback/generate");
            $feedback->assertOk()
                ->assertJsonPath('workoutId', $workoutId)
                ->assertJsonPath('confidence', 'low')
                ->assertJsonPath('summary.avgPaceSecPerKm', null)
                ->assertJsonStructure([
                    'praise',
                    'deviations',
                    'conclusions',
                    'planImpact',
                    'metrics',
                ]);

            $this->withHeaders($headers)
                ->getJson("/api/workouts/{$workoutId}/feedback")
                ->assertOk()
                ->assertJsonPath('workoutId', $workoutId);

            $refreshedPlan = $this->withHeaders($headers)
                ->getJson('/api/rolling-plan?days=14');
            $refreshedPlan->assertOk()
                ->assertJsonPath('windowDays', 14);

            $this->assertGreaterThanOrEqual(1, (int) $refreshedPlan->json('decisionTrace.facts.totalWorkouts'));
            $this->assertNotSame($initialPlan->json('inputsHash'), $refreshedPlan->json('inputsHash'));

            $this->withHeaders(['x-session-token' => (string) $login->json('sessionToken')])
                ->postJson('/api/auth/logout')
                ->assertOk()
                ->assertJson(['ok' => true]);
        } finally {
            $this->app['env'] = $originalEnv;
            Carbon::setTestNow();
        }
    }

    /**
     * @param mixed $sessions
     * @return array<string,mixed>|null
     */
    private function firstRunnableSession(mixed $sessions): ?array
    {
        if (! is_array($sessions)) {
            return null;
        }

        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }

            $type = (string) ($session['type'] ?? 'rest');
            $duration = (int) ($session['durationMin'] ?? 0);
            if (! in_array($type, ['rest', 'cross_training'], true) && $duration > 0) {
                return $session;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(string $username, string $sessionToken): array
    {
        return [
            'x-username' => $username,
            'x-session-token' => $sessionToken,
        ];
    }
}
