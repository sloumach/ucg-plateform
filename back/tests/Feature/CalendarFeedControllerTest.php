<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\CalendarFeed;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarFeedControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guest_and_calendar_writer_require_explicit_export_permission(): void
    {
        $org = Organization::factory()->create();
        $path = $this->path($org);
        $this->getJson($path)->assertUnauthorized();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['calendar.read', 'calendar.write']);
        $this->actingAsWithMfa($user);
        $this->getJson($path)->assertForbidden();
        $this->postJson($path, $this->input())->assertForbidden();
        $this->patchJson($path, ['version' => fake()->uuid(), 'confirm_revocation' => true])->assertForbidden();
        $this->assertDatabaseCount('calendar_feeds', 0);
    }

    public function test_issue_rotation_and_revocation_are_versioned_private_and_secret_is_only_returned_once(): void
    {
        [$org, $user] = $this->scenario();
        $this->getJson($this->path($org))->assertOk();
        config(['app.url' => 'https://trusted.example.test']);
        $first = $this->postJson($this->path($org), $this->input())->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('data.feed.token_hash')->assertJsonPath('data.feed.status', 'active')->json('data');
        $this->assertStringStartsWith('https://trusted.example.test/api/v1/calendar-subscriptions/', $first['url']);
        $queryString = parse_url($first['url'], PHP_URL_QUERY);
        $this->assertIsString($queryString);
        parse_str($queryString, $query);
        $this->assertArrayHasKey('token', $query);
        $this->assertIsString($query['token']);
        $stored = CalendarFeed::query()->sole();
        $this->assertSame(hash('sha256', $query['token']), $stored->token_hash);
        $this->assertSame($user->id, $stored->user_id);
        $this->getJson($this->path($org))->assertOk()->assertJsonPath('data.version', $stored->version)->assertJsonMissingPath('data.url')->assertJsonMissingPath('data.token_hash');
        $this->postJson($this->path($org), $this->input())->assertConflict()->assertJsonPath('code', 'CALENDAR_FEED_STALE');
        $second = $this->postJson($this->path($org), [...$this->input(), 'expected_version' => $stored->version])->assertCreated()->json('data');
        $this->assertSame($first['feed']['id'], $second['feed']['id']);
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertNotSame($stored->token_hash, $stored->refresh()->token_hash);
        $this->patchJson($this->path($org), ['version' => $first['feed']['version'], 'confirm_revocation' => true])->assertConflict();
        $this->patchJson($this->path($org), ['version' => $second['feed']['version'], 'confirm_revocation' => true])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertNull($stored->refresh()->token_hash);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($query['token'], $audit);
        $this->assertStringNotContainsString('token_hash', $audit);
        $this->assertDatabaseCount('calendar_feeds', 1);
        $this->assertDatabaseCount('organization_audit_events', 3);
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalid(): array
    {
        return ['consent' => [['confirm_export' => false], 'confirm_export'], 'string count' => [['before_days' => '7'], 'before_days'],
            'negative' => [['before_days' => -1], 'before_days'], 'past' => [['before_days' => 31], 'before_days'],
            'future' => [['after_days' => 91], 'after_days'], 'empty future' => [['after_days' => 0], 'after_days'],
            'combined' => [['before_days' => 30, 'after_days' => 90], 'after_days'], 'expiry' => [['expires_in_days' => 91], 'expires_in_days'],
            'closed' => [['user_id' => 1], 'user_id'], 'management' => [['management' => true], 'management'],
            'dotted' => [['token.hash' => 'x'], 'token.hash']];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalid')]
    public function test_invalid_input_never_creates_a_feed(array $changes, string $field): void
    {
        [$org] = $this->scenario();
        $this->postJson($this->path($org), [...$this->input(), ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('calendar_feeds', 0);
    }

    public function test_management_is_personal_and_audit_failure_rolls_back_new_secret(): void
    {
        [$org] = $this->scenario();
        CalendarFeed::factory()->create(['organization_id' => $org->id]);
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Unavailable audit'));
        $this->postJson($this->path($org), $this->input())->assertStatus(500);
        $this->assertDatabaseCount('calendar_feeds', 1);
        $this->getJson($this->path(Organization::factory()->create()))->assertNotFound();
    }

    /** @return array{Organization,User} */
    private function scenario(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['calendar.read', 'calendar.export']);
        $this->actingAsWithMfa($user);

        return [$org, $user];
    }

    private function path(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/calendar/feed';
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return ['expected_version' => null, 'before_days' => 7, 'after_days' => 60, 'expires_in_days' => 30, 'confirm_export' => true];
    }
}
