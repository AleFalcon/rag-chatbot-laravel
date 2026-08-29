<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_access_and_refresh_tokens(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'token_type', 'expires_in',
        ]);
        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertSame(20 * 60, $response->json('expires_in'));
    }

    public function test_login_with_invalid_credentials_returns_422(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_refresh_token_cannot_access_protected_routes(): void
    {
        $tokens = $this->loginAs(User::factory()->create());

        $this->asToken($tokens['refresh_token'])
            ->getJson('/api/documents')
            ->assertForbidden();
    }

    public function test_access_token_cannot_call_refresh_endpoint(): void
    {
        $tokens = $this->loginAs(User::factory()->create());

        $this->asToken($tokens['access_token'])
            ->postJson('/api/auth/refresh')
            ->assertForbidden();
    }

    public function test_refresh_revokes_the_old_token_pair(): void
    {
        $tokens = $this->loginAs(User::factory()->create());

        $refreshResponse = $this->withToken($tokens['refresh_token'])
            ->postJson('/api/auth/refresh')
            ->assertOk();

        // El access token viejo ya no debe funcionar.
        $this->asToken($tokens['access_token'])
            ->getJson('/api/documents')
            ->assertUnauthorized();

        // El refresh token viejo tampoco debe poder reusarse.
        $this->asToken($tokens['refresh_token'])
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();

        // El par nuevo emitido por el refresh sí debe funcionar.
        $this->asToken($refreshResponse->json('access_token'))
            ->getJson('/api/documents')
            ->assertOk();
    }

    public function test_logout_revokes_the_users_tokens(): void
    {
        $tokens = $this->loginAs(User::factory()->create());

        $this->asToken($tokens['access_token'])
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->asToken($tokens['access_token'])
            ->getJson('/api/documents')
            ->assertUnauthorized();
    }

    public function test_login_is_rate_limited_after_six_attempts_per_minute(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    private function loginAs(User $user): array
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json();
    }

    /**
     * Like withToken(), but also forgets cached auth guards first.
     *
     * The sanctum guard caches the resolved user/token on itself for the
     * lifetime of the container, and Laravel's test client reuses the same
     * container across every simulated request within a test method. Without
     * this, a test that authenticates with two different tokens back to back
     * would silently keep resolving the first one. Real HTTP requests are
     * unaffected since each gets a fresh container.
     */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
