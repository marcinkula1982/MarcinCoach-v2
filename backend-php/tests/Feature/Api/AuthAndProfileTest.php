<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
