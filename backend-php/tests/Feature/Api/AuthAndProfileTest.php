<?php

namespace Tests\Feature\Api;

use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthAndProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::create([
            'id' => 1,
            'name' => 'marcin',
            'email' => 'marcin@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_login_returns_session_token_and_username(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'marcin',
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['sessionToken', 'username']);
        $this->assertSame('marcin', $response->json('username'));
    }

    public function test_register_returns_session_token_and_username(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username' => 'anna',
            'email' => 'anna@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['sessionToken', 'username']);
        $this->assertSame('anna', $response->json('username'));
        $this->assertDatabaseHas('users', [
            'name' => 'anna',
            'email' => 'anna@example.com',
        ]);
    }

    public function test_register_rejects_duplicate_username(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username' => 'marcin',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Username already taken');
    }

    public function test_forgot_password_sends_reset_email_and_stores_token(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'identifier' => 'marcin@example.com',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'marcin@example.com',
        ]);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
            return $mail->hasTo('marcin@example.com')
                && $mail->user->name === 'marcin'
                && strlen($mail->token) >= 64
                && str_contains($mail->resetUrl, 'resetToken=');
        });
    }

    public function test_forgot_password_does_not_reveal_unknown_accounts(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'identifier' => 'unknown@example.com',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        Mail::assertNothingSent();
    }

    public function test_reset_password_updates_password_and_deletes_token(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', [
            'identifier' => 'marcin',
        ])->assertOk();

        $token = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        $this->assertNotNull($token);

        $response = $this->postJson('/api/auth/reset-password', [
            'identifier' => 'marcin@example.com',
            'token' => $token,
            'password' => 'new-password-123',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $user = User::query()->where('name', 'marcin')->firstOrFail();
        $this->assertTrue(Hash::check('new-password-123', (string) $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'marcin@example.com',
        ]);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => 'marcin@example.com',
            'token' => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'identifier' => 'marcin@example.com',
            'token' => 'invalid-token-123456',
            'password' => 'new-password-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid or expired password reset token');
    }

    public function test_get_and_update_profile_contract(): void
    {
        $get = $this->getJson('/api/me/profile');
        $get->assertOk();
        $get->assertJsonStructure([
            'id',
            'userId',
            'preferredRunDays',
            'preferredSurface',
            'goals',
            'constraints',
            'races',
            'availability',
            'health',
            'equipment',
            'onboardingCompleted',
            'hrZones',
            'createdAt',
            'updatedAt',
        ]);

        $update = $this->putJson('/api/me/profile', [
            'preferredRunDays' => '[1,3,5]',
            'preferredSurface' => 'TRAIL',
            'goals' => '["5k"]',
            'constraints' => '{"timezone":"Europe/Warsaw"}',
        ]);
        $update->assertOk();
        $update->assertJsonPath('preferredRunDays', '[1,3,5]');
        $update->assertJsonPath('preferredSurface', 'TRAIL');
    }

    public function test_profile_accepts_typed_onboarding_payload_and_marks_completed(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'goals' => '["half_marathon"]', // backward-compatible field
            'races' => [
                ['date' => '2026-09-20', 'distanceKm' => 21.1, 'priority' => 'A'],
            ],
            'availability' => [
                'runningDays' => ['mon', 'wed', 'sat'],
                'maxSessionMin' => 75,
            ],
            'health' => [
                'injuryHistory' => ['ankle_2024'],
                'currentPain' => false,
            ],
            'equipment' => [
                'watch' => true,
                'hrSensor' => true,
            ],
            'hrZones' => [
                'z1' => ['min' => 100, 'max' => 120],
                'z2' => ['min' => 121, 'max' => 140],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('onboardingCompleted', true);
        $response->assertJsonPath('races.0.priority', 'A');
        $response->assertJsonPath('availability.maxSessionMin', 75);
        $response->assertJsonPath('health.currentPain', false);
        $response->assertJsonPath('equipment.hrSensor', true);
        $response->assertJsonPath('hrZones.z1.min', 100);
        $response->assertJsonPath('hrZones.z2.max', 140);
    }

    public function test_profile_accepts_minimal_data_first_onboarding_payload(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'goals' => 'przebiec 10 km ponizej 50 minut',
            'races' => [
                ['date' => '2026-10-11', 'distanceKm' => 10.0, 'priority' => 'A'],
            ],
            'availability' => [
                'runningDays' => ['mon', 'wed', 'fri', 'sun'],
                'requestedTrainingDays' => 4,
                'unavailableDays' => ['sat'],
            ],
            'health' => [
                'currentPain' => false,
                'injuryHistory' => [],
            ],
            'equipment' => [
                'watch' => true,
                'hrSensor' => true,
            ],
            'constraints' => json_encode([
                'onboarding' => [
                    'source' => 'tcx',
                    'uploadedWorkoutsCount' => 6,
                    'confidenceHint' => 'standard',
                ],
            ]),
        ]);

        $response->assertOk();
        $response->assertJsonPath('onboardingCompleted', true);
        $response->assertJsonPath('goals', 'przebiec 10 km ponizej 50 minut');
        $this->assertEquals(10.0, (float) $response->json('primaryRace.distanceKm'));
        $response->assertJsonPath('availability.runningDays', ['mon', 'wed', 'fri', 'sun']);
        $response->assertJsonPath('health.currentPain', false);
        $response->assertJsonPath('equipment.hrSensor', true);
    }

    // --- M1 beyond minimum: primaryRace projection ---

    public function test_profile_update_projects_primary_race_for_future_a_race(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'races' => [
                ['date' => '2027-04-15', 'distanceKm' => 42.2, 'priority' => 'A'],
                ['date' => '2026-09-20', 'distanceKm' => 21.1, 'priority' => 'B'],
            ],
            'availability' => ['runningDays' => ['mon', 'wed'], 'maxSessionMin' => 60],
            'health' => ['injuryHistory' => [], 'currentPain' => false],
            'equipment' => ['watch' => true, 'hrSensor' => false],
        ]);

        $response->assertOk();
        $response->assertJsonPath('primaryRace.priority', 'A');
        $response->assertJsonPath('primaryRace.distanceKm', 42.2);
        $response->assertJsonPath('primaryRace.date', '2027-04-15');
    }

    public function test_profile_update_selects_nearest_future_b_race_when_no_a(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'races' => [
                ['date' => '2027-06-01', 'distanceKm' => 42.2, 'priority' => 'B'],
                ['date' => '2026-11-10', 'distanceKm' => 10.0, 'priority' => 'B'],
            ],
            'availability' => ['runningDays' => ['mon'], 'maxSessionMin' => 60],
            'health' => ['injuryHistory' => [], 'currentPain' => false],
            'equipment' => ['watch' => true, 'hrSensor' => false],
        ]);

        $response->assertOk();
        // Nearest future B is 2026-11-10
        $response->assertJsonPath('primaryRace.priority', 'B');
        $response->assertJsonPath('primaryRace.date', '2026-11-10');
    }

    public function test_primary_race_null_when_all_races_in_past(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'races' => [
                ['date' => '2020-01-01', 'distanceKm' => 42.2, 'priority' => 'A'],
            ],
            'availability' => ['runningDays' => ['mon'], 'maxSessionMin' => 60],
            'health' => ['injuryHistory' => [], 'currentPain' => false],
            'equipment' => ['watch' => true, 'hrSensor' => false],
        ]);

        $response->assertOk();
        $response->assertJsonPath('primaryRace', null);
    }

    // --- M1 beyond minimum: quality score in response ---

    public function test_profile_response_contains_quality_keys(): void
    {
        $response = $this->getJson('/api/me/profile');
        $response->assertOk();
        $response->assertJsonStructure([
            'primaryRace',
            'quality' => ['score', 'breakdown'],
        ]);
    }

    public function test_profile_quality_score_increases_with_more_data(): void
    {
        $empty = $this->getJson('/api/me/profile');
        $emptyScore = $empty->json('quality.score');

        $this->putJson('/api/me/profile', [
            'preferredSurface' => 'TRAIL',
            'races' => [['date' => '2027-03-01', 'distanceKm' => 42.2, 'priority' => 'A']],
            'availability' => ['runningDays' => ['mon', 'wed', 'fri'], 'maxSessionMin' => 75],
            'health' => ['injuryHistory' => [], 'currentPain' => false],
            'equipment' => ['watch' => true, 'hrSensor' => true],
            'hrZones' => [
                'z1' => ['min' => 100, 'max' => 120],
                'z2' => ['min' => 121, 'max' => 140],
                'z3' => ['min' => 141, 'max' => 160],
                'z4' => ['min' => 161, 'max' => 175],
                'z5' => ['min' => 176, 'max' => 195],
            ],
        ]);

        $full = $this->getJson('/api/me/profile');
        $fullScore = $full->json('quality.score');

        $this->assertGreaterThan($emptyScore, $fullScore);
    }

    // --- M1 beyond minimum: HR zones cross-field validation ---

    public function test_hr_zones_cross_field_min_must_be_less_than_max(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'hrZones' => [
                'z1' => ['min' => 130, 'max' => 120], // min > max — invalid
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hrZones.z1']);
    }

    public function test_hr_zones_cross_field_must_be_monotonically_ascending(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'hrZones' => [
                'z1' => ['min' => 100, 'max' => 150], // z1.max > z2.min
                'z2' => ['min' => 130, 'max' => 160],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hrZones.z2']);
    }

    public function test_hr_zones_valid_monotonic_passes_validation(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'hrZones' => [
                'z1' => ['min' => 100, 'max' => 120],
                'z2' => ['min' => 121, 'max' => 140],
                'z3' => ['min' => 141, 'max' => 160],
                'z4' => ['min' => 161, 'max' => 175],
                'z5' => ['min' => 176, 'max' => 195],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('hrZones.z3.min', 141);
    }

    // --- M1 beyond minimum: availability.runningDays canonicalizes preferred_run_days ---

    public function test_availability_running_days_is_canonical_source(): void
    {
        $this->putJson('/api/me/profile', [
            'availability' => ['runningDays' => ['tue', 'thu', 'sat'], 'maxSessionMin' => 60],
            'health' => ['injuryHistory' => [], 'currentPain' => false],
            'equipment' => ['watch' => true, 'hrSensor' => false],
        ]);

        $get = $this->getJson('/api/me/profile');
        $get->assertOk();
        $get->assertJsonPath('availability.runningDays', ['tue', 'thu', 'sat']);
    }

    public function test_profile_partial_json_sections_preserve_existing_keys(): void
    {
        $this->putJson('/api/me/profile', [
            'availability' => ['runningDays' => ['mon', 'wed', 'fri'], 'maxSessionMin' => 90],
            'health' => ['injuryHistory' => ['ankle_2024'], 'currentPain' => false],
            'equipment' => ['watch' => true, 'hrSensor' => true],
        ])->assertOk();

        $response = $this->putJson('/api/me/profile', [
            'availability' => ['runningDays' => ['tue', 'thu']],
            'health' => ['currentPain' => true],
            'equipment' => ['hrSensor' => false],
        ]);

        $response->assertOk();
        $response->assertJsonPath('availability.runningDays', ['tue', 'thu']);
        $response->assertJsonPath('availability.maxSessionMin', 90);
        $response->assertJsonPath('health.currentPain', true);
        $response->assertJsonPath('health.injuryHistory', ['ankle_2024']);
        $response->assertJsonPath('equipment.watch', true);
        $response->assertJsonPath('equipment.hrSensor', false);
    }

    public function test_profile_rejects_invalid_typed_payload(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'races' => [
                ['date' => 'not-a-date', 'distanceKm' => -5, 'priority' => 'X'],
            ],
            'availability' => [
                'runningDays' => ['monday'], // invalid enum
                'maxSessionMin' => 5, // below minimum
            ],
            'health' => [
                'currentPain' => 'yes', // not boolean
            ],
            'equipment' => [
                'watch' => 'sometimes', // not boolean
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'races.0.date',
            'races.0.distanceKm',
            'races.0.priority',
            'availability.runningDays.0',
            'availability.maxSessionMin',
            'health.currentPain',
            'equipment.watch',
        ]);
    }

    public function test_onboarding_completed_remains_false_when_required_sections_missing(): void
    {
        $response = $this->putJson('/api/me/profile', [
            'goals' => '["5k"]',
            'availability' => ['runningDays' => ['tue', 'thu'], 'maxSessionMin' => 60],
            // no health / no equipment
        ]);

        $response->assertOk();
        $response->assertJsonPath('onboardingCompleted', false);
    }

    // --- Auth enforcement (non-testing env) ---

    public function test_protected_endpoint_returns_401_when_no_token_sent(): void
    {
        // Simulate non-testing environment to bypass the testing shortcut
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/me/profile');

        $this->app['env'] = 'testing';

        $response->assertUnauthorized();
    }

    public function test_protected_endpoint_returns_401_when_token_is_invalid(): void
    {
        $this->app['env'] = 'production';

        $response = $this->withHeaders([
            'x-username' => 'marcin',
            'x-session-token' => 'not-a-real-token',
        ])->getJson('/api/me/profile');

        $this->app['env'] = 'testing';

        $response->assertUnauthorized();
    }

    public function test_protected_endpoint_returns_401_when_username_does_not_match_token(): void
    {
        $this->app['env'] = 'production';

        // Issue a valid token for 'marcin', then use it with a different username
        $svc = app(SessionTokenService::class);
        $token = $svc->issueToken(1, 'marcin');

        $response = $this->withHeaders([
            'x-username' => 'other',
            'x-session-token' => $token,
        ])->getJson('/api/me/profile');

        $this->app['env'] = 'testing';

        $response->assertUnauthorized();
    }

    public function test_protected_endpoint_returns_401_when_only_username_sent_without_token(): void
    {
        $this->app['env'] = 'production';

        // Without a valid token the username-only path must be rejected
        $response = $this->withHeaders([
            'x-username' => 'marcin',
        ])->getJson('/api/me/profile');

        $this->app['env'] = 'testing';

        $response->assertUnauthorized();
    }

    // --- Logout / revoke ---

    public function test_logout_returns_ok(): void
    {
        $svc = app(SessionTokenService::class);
        $token = $svc->issueToken(1, 'marcin');

        $response = $this->withHeaders([
            'x-session-token' => $token,
        ])->postJson('/api/auth/logout');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_logout_invalidates_token(): void
    {
        $this->app['env'] = 'production';

        $svc = app(SessionTokenService::class);
        $token = $svc->issueToken(1, 'marcin');

        // Token works before logout
        $before = $this->withHeaders([
            'x-username' => 'marcin',
            'x-session-token' => $token,
        ])->getJson('/api/me/profile');
        $before->assertOk();

        // Logout
        $this->withHeaders(['x-session-token' => $token])->postJson('/api/auth/logout');

        // Same token must now be rejected
        $after = $this->withHeaders([
            'x-username' => 'marcin',
            'x-session-token' => $token,
        ])->getJson('/api/me/profile');

        $this->app['env'] = 'testing';

        $after->assertUnauthorized();
    }

    public function test_logout_with_no_token_header_returns_ok(): void
    {
        // Calling logout without a token should silently succeed
        $response = $this->postJson('/api/auth/logout');

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }
}
