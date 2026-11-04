<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

class AuthTest extends ApiTestCase
{
    public function test_every_api_route_except_login_requires_a_token(): void
    {
        $open = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/') && ! str_contains($r->uri(), '{fallbackPlaceholder}'))
            ->reject(fn ($r) => in_array('auth:sanctum', $r->gatherMiddleware(), true))
            ->map(fn ($r) => $r->uri())
            ->values()
            ->all();

        $this->assertSame(['api/auth/login'], $open);
    }

    public function test_request_without_token_gets_401_json(): void
    {
        $this->get('/api/products')
            ->assertStatus(401)
            ->assertJson(['message' => 'Geçerli bir token gerekli. Lütfen giriş yapın.']);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('1|sahte-token')->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_login_returns_token_and_permissions(): void
    {
        $user = $this->userWithRole('personel');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'telefon',
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotNull($response->json('expires_at'));
        $this->assertEqualsCanonicalizing(['products.view', 'usage.create'], $response->json('user.permissions'));

        $this->withToken($response->json('token'))->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('user.email', $user->email);
    }

    public function test_wrong_password_fails(): void
    {
        $user = $this->userWithRole('personel');

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'yanlis', 'device_name' => 'x'])
            ->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->userWithRole('personel', ['is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'x'])
            ->assertStatus(403);
    }

    public function test_deactivated_user_existing_token_stops_working(): void
    {
        $user = $this->userWithRole('personel');
        $token = $this->tokenFor($user);

        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(403);
    }

    public function test_logout_revokes_token(): void
    {
        $user = $this->userWithRole('personel');
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->userWithRole('personel');
        $token = $this->tokenFor($user);

        $this->travel(8)->days();

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_session_cookie_is_not_accepted_instead_of_token(): void
    {
        $this->actingAs($this->userWithRole('personel'), 'web')
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
