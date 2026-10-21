<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_authenticates_user_via_api_guard(): void
    {
        $user = User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ahmed@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('expires_in', config('jwt.ttl') * 60);

        $token = $response->json('access_token');

        $this->assertIsString($token);
        $this->assertNotSame('Password123!', $response->getContent());
        $this->assertSame(2, substr_count($token, '.'), 'Token should have JWT structure (header.payload.signature)');

        $authenticated = auth('api')->setToken($token)->user();

        $this->assertNotNull($authenticated);
        $this->assertSame($user->id, $authenticated->id);
        $this->assertTrue(auth('api')->check());
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $wrongPassword = $this->postJson('/api/login', [
            'email' => 'ahmed@example.com',
            'password' => 'WrongPassword1!',
        ]);

        $wrongPassword->assertStatus(401)
            ->assertExactJson(['message' => 'Invalid credentials.']);

        $unknownEmail = $this->postJson('/api/login', [
            'email' => 'ghost@example.com',
            'password' => 'Password123!',
        ]);

        $unknownEmail->assertStatus(401)
            ->assertExactJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_validation_fails(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'not-an-email',
            'password' => null,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);

        $missing = $this->postJson('/api/login', []);

        $missing->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
