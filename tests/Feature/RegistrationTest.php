<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_succeeds_and_hashes_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201);

        $user = User::whereEmail('ahmed@example.com')->first();

        $this->assertNotNull($user);

        $response->assertExactJson([
            'message' => 'User registered successfully.',
            'user' => [
                'id' => $user->id,
                'name' => 'Ahmed Ali',
                'email' => 'ahmed@example.com',
            ],
        ]);

        $this->assertNotSame('Password123!', $user->password);
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertStringStartsWith('$2y$', $user->password);
    }

    public function test_registration_fails_with_invalid_input(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_response_does_not_expose_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed2@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertStringNotContainsString('Password123!', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }
}
