<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->group(function (): void {
            Route::get('/api/testing/forbidden', static function (): never {
                throw new AuthorizationException;
            });
            Route::get('/api/testing/conflict', static function (): never {
                throw new DomainConflictException('La ressource est déjà utilisée.', 'TEST_CONFLICT');
            });
            Route::get('/api/testing/failure', static function (): never {
                throw new RuntimeException('Sensitive implementation detail.');
            });
        });
    }

    public function test_returns_standard_401_response(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $this->assertStandardError($response, 401, 'AUTHENTICATION_REQUIRED');
    }

    public function test_returns_standard_403_response(): void
    {
        $response = $this->getJson('/api/testing/forbidden');

        $this->assertStandardError($response, 403, 'AUTHORIZATION_DENIED');
    }

    public function test_returns_standard_404_response(): void
    {
        $response = $this->getJson('/api/v1/missing-resource');

        $this->assertStandardError($response, 404, 'RESOURCE_NOT_FOUND');
    }

    public function test_returns_standard_409_response(): void
    {
        $response = $this->getJson('/api/testing/conflict');

        $this->assertStandardError($response, 409, 'TEST_CONFLICT');
    }

    public function test_returns_standard_422_response_with_validation_errors(): void
    {
        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/auth/login');

        $this->assertStandardError($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_returns_standard_429_response_after_login_limit_is_exceeded(): void
    {
        $response = null;

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this
                ->withServerVariables(['REMOTE_ADDR' => '192.0.2.55'])
                ->withHeaders($this->spaHeaders())
                ->postJson('/api/v1/auth/login', [
                    'email' => 'rate-limit@ucg.test',
                    'password' => 'invalid-password',
                ]);
        }

        $this->assertInstanceOf(TestResponse::class, $response);
        $this->assertStandardError($response, 429, 'RATE_LIMIT_EXCEEDED');
    }

    public function test_returns_safe_standard_500_response_without_debug_details(): void
    {
        $response = $this->getJson('/api/testing/failure');

        $this->assertStandardError($response, 500, 'INTERNAL_ERROR');
        $response
            ->assertJsonMissing(['exception'])
            ->assertJsonMissing(['file'])
            ->assertJsonMissing(['trace'])
            ->assertJsonMissing(['message' => 'Sensitive implementation detail.']);
    }

    /** @param TestResponse<Response> $response */
    private function assertStandardError(TestResponse $response, int $status, string $code): void
    {
        $response
            ->assertStatus($status)
            ->assertJsonPath('code', $code)
            ->assertJsonStructure([
                'message',
                'code',
                'errors',
                'meta' => ['request_id'],
            ]);

        $this->assertSame(
            $response->json('meta.request_id'),
            $response->headers->get('X-Request-ID'),
        );
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
