<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'member@ucg.test',
            'password' => 'valid-password',
        ]);

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/auth/login', [
                'email' => 'MEMBER@UCG.TEST',
                'password' => 'valid-password',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'member@ucg.test');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_invalid_credentials_without_disclosing_the_cause(): void
    {
        User::factory()->create([
            'email' => 'member@ucg.test',
            'password' => 'valid-password',
        ]);

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/auth/login', [
                'email' => 'member@ucg.test',
                'password' => 'invalid-password',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_current_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_their_profile_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'member@ucg.test',
            'password' => 'valid-password',
        ]);

        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/auth/login', [
                'email' => 'member@ucg.test',
                'password' => 'valid-password',
            ])
            ->assertOk();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Déconnexion effectuée.');

        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->assertGuest();
    }

    /** @return array<string, string> */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
