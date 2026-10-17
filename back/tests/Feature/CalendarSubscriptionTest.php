<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Application\Services\CalendarFeedService;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarFeed;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarSubscriptionTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_sessionless_download_has_minimal_projection_and_no_management_privilege(): void
    {
        [$org, $user, $feed, $url] = $this->scenario();
        $other = User::factory()->create();
        $base = ['organization_id' => $org->id, 'created_by' => $other->id];
        CalendarActivity::factory()->shared()->create([...$base, 'title' => 'VISIBLE_TITLE', 'description' => 'SECRET_NOTE', 'location' => 'SECRET_ROOM', 'url' => 'https://secret.test/SECRET_URL']);
        CalendarActivity::factory()->create([...$base, 'title' => 'SECRET_PRIVATE']);
        CalendarActivity::factory()->create([...$base, 'created_by' => $user->id, 'title' => 'PERSONAL_TITLE']);
        CalendarActivity::factory()->shared()->create([...$base, 'title' => 'SECRET_ARCHIVED', 'archived_at' => now()]);
        CalendarActivity::factory()->shared()->create(['title' => 'SECRET_FOREIGN']);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeaderMissing('ETag');
        $raw = $response->getContent();
        $this->assertIsString($raw);
        $body = str_replace("\r\n ", '', $raw);
        $this->assertStringContainsString('SUMMARY:VISIBLE_TITLE', $body);
        $this->assertStringContainsString('SUMMARY:PERSONAL_TITLE', $body);
        foreach (['SECRET_', 'ATTENDEE', 'DESCRIPTION', 'LOCATION', 'ORGANIZER', $org->id, $feed->id, $user->email] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertSame(2, substr_count($body, 'BEGIN:VEVENT'));
        $this->assertDatabaseCount('calendar_feeds', 1);
    }

    public function test_live_person_and_team_membership_control_visibility_on_every_download(): void
    {
        [$org, $user, , $url] = $this->scenario();
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $membership = TeamMembership::factory()->create(['organization_id' => $org->id, 'team_id' => $team->id, 'person_id' => $person->id]);
        $other = User::factory()->create();
        $activity = CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $other->id, 'title' => 'TEAM_TITLE',
            'team_id' => $team->id, 'visibility' => 'team', 'sharing_authorized_by' => $other->id, 'sharing_authorized_at' => now()]);
        $private = CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $other->id, 'title' => 'INVITED_TITLE']);
        CalendarParticipant::factory()->create(['organization_id' => $org->id, 'activity_id' => $private->id, 'person_id' => $person->id]);
        $this->get($url)->assertOk()->assertSee('TEAM_TITLE')->assertSee('INVITED_TITLE');
        $membership->forceFill(['ends_at' => now()->subSecond()])->save();
        $this->get($url)->assertOk()->assertDontSee('TEAM_TITLE')->assertSee('INVITED_TITLE');
        $person->forceFill(['status' => 'former'])->save();
        $this->get($url)->assertOk()->assertDontSee('INVITED_TITLE');
    }

    /** @return array<string,array{string}> */
    public static function revokedAccess(): array
    {
        return array_combine($keys = ['grant', 'membership', 'mfa', 'email', 'technical', 'organization'], array_map(fn (string $key): array => [$key], $keys));
    }

    #[DataProvider('revokedAccess')]
    public function test_current_account_and_tenant_eligibility_is_required_without_a_session(string $change): void
    {
        [$org, $user, , $url] = $this->scenario();
        $this->get($url)->assertOk();
        match ($change) {
            'grant' => OrganizationRoleAssignment::query()->where('user_id', $user->id)->update(['status' => 'revoked']),
            'membership' => OrganizationMembership::query()->where('user_id', $user->id)->update(['status' => 'suspended']),
            'mfa' => $user->forceFill(['mfa_confirmed_at' => null])->save(),
            'email' => $user->forceFill(['email_verified_at' => null])->save(),
            'technical' => PlatformRole::factory()->superadmin()->create(['user_id' => $user->id]),
            'organization' => $org->forceFill(['status' => 'suspended'])->save(),
            default => throw new \InvalidArgumentException('Unknown eligibility change.'),
        };
        $this->get($url)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_rotation_expiry_revocation_and_cross_tenant_tokens_cannot_reuse_cached_access(): void
    {
        [$org, $user, $feed, $url] = $this->scenario();
        $service = app(CalendarFeedService::class);
        $context = $this->personContext($org, $user);
        $this->get($url)->assertOk();
        $rotated = $service->issue($context, ['expected_version' => $feed->version, 'before_days' => 7, 'after_days' => 60, 'expires_in_days' => 1, 'confirm_export' => true], null);
        $new = parse_url($rotated->url, PHP_URL_PATH).'?'.parse_url($rotated->url, PHP_URL_QUERY);
        $this->get($url, ['If-None-Match' => '*', 'If-Modified-Since' => now()->toRfc7231String()])->assertNotFound();
        $this->get($new)->assertOk();
        $this->get(str_replace($org->id, Organization::factory()->create()->id, $new))->assertNotFound();
        $this->travel(1)->days();
        $this->get($new)->assertNotFound();
        $this->travelBack();
        $service->revoke($context, ['version' => $rotated->feed->version, 'confirm_revocation' => true], null);
        $this->get($new, ['If-None-Match' => '*'])->assertNotFound();
        $this->assertNull($feed->refresh()->token_hash);
    }

    public function test_window_uses_utc_half_open_bounds_and_preserves_stable_uids(): void
    {
        [$org, $user, $feed, $url] = $this->scenario();
        $this->travelTo('2026-10-25 00:30:00');
        $feed->forceFill(['before_days' => 0, 'after_days' => 1, 'expires_at' => now()->addDays(5)])->save();
        foreach ([['lower', '2026-10-24 23:00:00', '2026-10-25 00:00:00'], ['overlap', '2026-10-24 23:30:00', '2026-10-25 00:30:00'],
            ['dst', '2026-10-25 00:30:00', '2026-10-25 01:30:00'], ['upper', '2026-10-26 00:00:00', '2026-10-26 01:00:00']] as [$title, $start, $end]) {
            CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $user->id, 'title' => $title, 'starts_at' => $start, 'ends_at' => $end]);
        }
        $body = $this->body($url);
        $this->assertStringContainsString('SUMMARY:overlap', $body);
        $this->assertStringContainsString('SUMMARY:dst', $body);
        $this->assertStringNotContainsString('SUMMARY:lower', $body);
        $this->assertStringNotContainsString('SUMMARY:upper', $body);
        $this->assertStringContainsString('DTSTART:20261025T003000Z', $body);
        preg_match_all('/^UID:(.+)$/m', $body, $first);
        $this->travel(1)->hours();
        preg_match_all('/^UID:(.+)$/m', $this->body($url), $second);
        $this->assertSame($first[1], $second[1]);
    }

    public function test_overflow_fails_closed_instead_of_silently_truncating_and_has_one_bounded_event_query(): void
    {
        [$org, $user, , $url] = $this->scenario();
        CalendarActivity::factory()->count(501)->create(['organization_id' => $org->id, 'created_by' => $user->id]);
        DB::enableQueryLog();
        $this->get($url)->assertConflict()->assertJsonPath('code', 'CALENDAR_FEED_LIMIT_EXCEEDED')->assertHeader('Cache-Control', 'no-store, private');
        $events = array_values(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "calendar_activities"')));
        DB::disableQueryLog();
        $this->assertCount(1, $events);
        $this->assertStringContainsString('limit 501', $events[0]['query']);
    }

    public function test_invalid_secrets_are_generic_and_throttling_remains_private(): void
    {
        [, , , $url] = $this->scenario();
        $this->get(Str::before($url, '?').'?token=invalid')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(Str::before($url, '?').'?token[]=invalid')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url.'&management=1')->assertNotFound();
        for ($i = 0; $i < 12; $i++) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertStatus(429)->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
    }

    private function body(string $url): string
    {
        $body = $this->get($url)->assertOk()->getContent();
        $this->assertIsString($body);

        return str_replace("\r\n ", '', $body);
    }

    public function test_revocation_between_capability_resolution_and_rendering_is_rechecked(): void
    {
        [$org, $user, $feed, $url] = $this->scenario();
        $service = app(CalendarFeedService::class);
        parse_str(Str::after($url, '?'), $query);
        $token = $query['token'] ?? null;
        $this->assertIsString($token);
        $subscription = $service->open($org->id, $feed->id, $token, (string) Str::uuid());
        $service->revoke($this->personContext($org, $user), ['version' => $feed->version, 'confirm_revocation' => true], null);
        $this->expectException(ModelNotFoundException::class);
        $service->render($subscription);
    }

    public function test_errors_are_private_and_remove_secret_from_request_uri(): void
    {
        [, , , $url] = $this->scenario();
        $this->mock(CalendarRepository::class)->shouldReceive('feed')->once()->andThrow(new \RuntimeException('Backend detail'));
        $this->get($url)->assertStatus(503)->assertJsonPath('code', 'CALENDAR_FEED_UNAVAILABLE')->assertDontSee('Backend detail')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('token=', $this->app['request']->getUri());
    }

    public function test_account_unavailability_during_generation_remains_a_generic_404(): void
    {
        [, , , $url] = $this->scenario();
        $this->mock(TenantBusinessGuard::class)->shouldReceive('lock')->once()
            ->andThrow(new DomainConflictException('Account became unavailable', 'PERSON_ACCOUNT_UNAVAILABLE'));
        $this->get($url)->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND')->assertDontSee('PERSON_ACCOUNT_UNAVAILABLE')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    /** @return array{Organization,User,CalendarFeed,string} */
    private function scenario(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['calendar.read', 'calendar.export', 'calendar.write']);
        $token = bin2hex(random_bytes(32));
        $feed = CalendarFeed::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token)]);

        return [$org, $user, $feed, '/api/v1/calendar-subscriptions/'.$org->id.'/'.$feed->id.'.ics?token='.$token];
    }
}
