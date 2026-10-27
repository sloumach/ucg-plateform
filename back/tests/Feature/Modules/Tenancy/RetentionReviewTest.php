<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\RetentionReviewQuery;
use App\Modules\Tenancy\Application\Services\OrganizationPurgeService;
use App\Modules\Tenancy\Application\Services\RetentionReviewService;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Contracts\RetentionReviewRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

class RetentionReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_inventory_is_complete_paginated_and_scoped_without_business_writes(): void
    {
        $organization = $this->operator();
        Person::factory()->create(['organization_id' => $organization->id]);
        Person::factory()->count(2)->create();
        $before = app(PortabilityRepository::class)->counts($organization->id);
        Queue::fake();

        $result = $this->listing($organization, 'inventory', 1, 3);

        $this->assertSame(18, $result['pagination']['total']);
        $this->assertSame(['organization', 'history', 'people'], array_column($result['data'], 'family'));
        $this->assertSame(1, $result['data'][2]['counts']['people']);
        $this->assertSame('missing', $result['policy_status']);
        $this->assertFalse($result['deletion_supported']);
        $this->assertContains('caches', $result['unverified_copies']);
        $this->assertSame($before, app(PortabilityRepository::class)->counts($organization->id));
        $this->assertDatabaseCount('tenant_portability_operations', 0);
        Queue::assertNothingPushed();
    }

    #[Group('tenant-isolation')]
    public function test_people_preview_omits_private_fields_and_never_crosses_organization_scope(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fixture-keep.txt', 'unchanged');
        $organization = $this->operator();
        $other = Organization::factory()->withMfaOwner()->create();
        $shared = User::factory()->create();
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id, 'user_id' => $shared->id, 'pseudonym' => 'PRIVATE_PSEUDONYM', 'first_name' => 'PRIVATE_IDENTITY']);
        $foreign = Person::factory()->create(['organization_id' => $other->id, 'user_id' => $shared->id]);
        $foreignBefore = DB::table('people')->where('organization_id', $other->id)->first();
        PersonPeriod::factory()->create(['organization_id' => $organization->id, 'person_id' => $person->id]);

        $result = $this->listing($organization, 'people');

        $this->assertSame([$person->id], array_column($result['data'], 'person_id'));
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_PSEUDONYM', 'PRIVATE_IDENTITY', $foreign->id, 'birth_date', 'contact_email', 'user_id'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $row = $result['data'][0];
        $this->assertContains('global_account_protected', $row['reasons']);
        $this->assertContains('age_procedure_unverified', $row['reasons']);
        $this->assertSame(1, $row['dependencies']['person_periods']);
        $this->assertFalse($row['dependency_inventory_complete']);
        $this->assertFalse($row['deletion_authorized']);
        $this->assertSame((array) $foreignBefore, (array) DB::table('people')->where('organization_id', $other->id)->first());
        $this->assertModelExists($shared);
        $this->assertSame('unchanged', Storage::disk('local')->get('fixture-keep.txt'));
    }

    public function test_recorded_departure_uses_only_server_policy_and_never_authorizes_deletion(): void
    {
        $organization = $this->operator();
        $person = Person::factory()->create(['organization_id' => $organization->id, 'status' => 'former']);
        PersonDeparture::factory()->create(['organization_id' => $organization->id, 'person_id' => $person->id,
            'departed_at' => '2026-09-01 00:00:00']);
        config(['retention.review_policies.'.$organization->id => $this->reviewPolicy()]);

        $result = $this->listing($organization, 'people');

        $this->assertSame('configured_review_only', $result['policy_status']);
        $this->assertSame('2026-10-01', $result['data'][0]['active_review_on']);
        $this->assertSame('2026-11-30', $result['data'][0]['archive_review_on']);
        $this->assertTrue($result['data'][0]['review_date_reached']);
        $this->assertSame('review_required', $result['data'][0]['outcome']);
        $this->assertFalse($result['data'][0]['deletion_authorized']);
        $this->assertModelExists($person);
    }

    public function test_former_without_departure_and_open_obligations_never_receive_an_invented_deadline(): void
    {
        $organization = $this->operator();
        $person = Person::factory()->create(['organization_id' => $organization->id, 'status' => 'former']);
        PersonDepartureItem::factory()->create(['organization_id' => $organization->id, 'person_id' => $person->id, 'status' => 'open']);
        config(['retention.review_policies.'.$organization->id => $this->reviewPolicy()]);

        $row = $this->listing($organization, 'people')['data'][0];

        $this->assertNull($row['active_review_on']);
        $this->assertContains('closure_event_missing', $row['reasons']);
        $this->assertContains('open_obligations_require_scoped_review', $row['reasons']);
    }

    public function test_archived_organization_can_be_reviewed_only_by_its_explicit_server_operator(): void
    {
        $organization = $this->operator();
        $organization->forceFill(['status' => OrganizationStatus::Archived])->save();

        $result = $this->listing($organization, 'people');

        $this->assertSame(0, $result['pagination']['total']);
        $this->assertSame([], $result['data']);
        $this->assertSame('Aucun dossier ne correspond à cet inventaire.', $result['notice']);
    }

    /** @return array<string, array{string}> */
    public static function refusedOperators(): array
    {
        return ['not scoped' => ['scope'], 'unverified' => ['email'], 'without MFA' => ['mfa'], 'technical account' => ['technical']];
    }

    #[DataProvider('refusedOperators')]
    public function test_operator_prerequisites_cannot_be_replaced_by_ownership(string $change): void
    {
        $organization = $this->operator();
        if ($change === 'scope') {
            config(['retention.review_operators' => []]);
        } elseif ($change === 'email') {
            User::findOrFail($organization->owner_user_id)->forceFill(['email_verified_at' => null])->save();
        } elseif ($change === 'mfa') {
            User::findOrFail($organization->owner_user_id)->forceFill(['mfa_confirmed_at' => null])->save();
        } else {
            PlatformRole::factory()->create(['user_id' => $organization->owner_user_id]);
        }

        $this->assertSame(1, Artisan::call('organizations:retention-review', $this->commandOptions($organization)));
        $this->assertStringContainsString('Cet accès opérateur', Artisan::output());
        $this->assertDatabaseCount('tenant_portability_operations', 0);
    }

    #[Group('tenant-isolation')]
    public function test_operator_scope_for_alpha_never_authorizes_beta_even_with_same_owner(): void
    {
        $organization = $this->operator();
        $other = Organization::factory()->create(['owner_user_id' => $organization->owner_user_id]);

        $this->assertSame(1, Artisan::call('organizations:retention-review', $this->commandOptions($other)));
        $this->assertStringContainsString('Cet accès opérateur', Artisan::output());
    }

    public function test_revoking_operator_scope_during_read_prevents_returning_a_partial_report(): void
    {
        $organization = $this->operator();
        Person::factory()->create(['organization_id' => $organization->id]);
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use (&$revoked): void {
            if (! $revoked && str_contains($query->sql, '"people" as "p"')) {
                $revoked = true;
                config(['retention.review_operators' => []]);
            }
        });

        $this->assertSame(1, Artisan::call('organizations:retention-review', $this->commandOptions($organization)));
        $this->assertTrue($revoked);
        $this->assertStringNotContainsString('person_id', Artisan::output());
    }

    public function test_repository_query_count_does_not_grow_with_page_size(): void
    {
        $organization = $this->operator();
        Person::factory()->create(['organization_id' => $organization->id]);
        $repository = app(RetentionReviewRepository::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $one = $repository->people($organization->id, 100, 1);
        $repository->dependencies($organization->id, array_column($one->items(), 'id'));
        $count = count(DB::getQueryLog());
        Person::factory()->count(20)->create(['organization_id' => $organization->id]);
        DB::flushQueryLog();
        $many = $repository->people($organization->id, 100, 1);
        $repository->dependencies($organization->id, array_column($many->items(), 'id'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(21, $many->items());
        $this->assertSame($count, $queries);
        $this->assertLessThanOrEqual(24, $queries);
    }

    public function test_cross_module_references_are_counted_without_reading_their_private_contents(): void
    {
        $organization = $this->operator();
        $person = Person::factory()->create(['organization_id' => $organization->id]);
        $team = Team::factory()->create(['organization_id' => $organization->id]);
        TeamMembership::factory()->create(['organization_id' => $organization->id, 'team_id' => $team->id, 'person_id' => $person->id]);
        $activity = CalendarActivity::factory()->create(['organization_id' => $organization->id]);
        CalendarParticipant::factory()->create(['organization_id' => $organization->id, 'activity_id' => $activity->id, 'person_id' => $person->id]);
        $contract = ContractRecord::factory()->create(['organization_id' => $organization->id, 'responsible_person_id' => $person->id]);
        (new ContractParty)->forceFill(['organization_id' => $organization->id, 'contract_id' => $contract->id,
            'kind' => 'person', 'person_id' => $person->id, 'name' => 'PRIVATE_CONTRACT_PARTY', 'position' => 1])->save();
        $profile = GameProfile::factory()->create(['organization_id' => $organization->id, 'person_id' => $person->id]);
        ExternalGameAccount::factory()->create(['organization_id' => $organization->id, 'game_profile_id' => $profile->id,
            'external_id' => 'PRIVATE_EXTERNAL_ACCOUNT']);

        $result = $this->listing($organization, 'people');

        foreach (['team_memberships', 'calendar_participants', 'contracts', 'contract_parties', 'person_game_profiles', 'person_external_accounts'] as $table) {
            $this->assertSame(1, $result['data'][0]['dependencies'][$table]);
        }
        $this->assertStringNotContainsString('PRIVATE_CONTRACT_PARTY', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('PRIVATE_EXTERNAL_ACCOUNT', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_review_policy_never_enables_the_existing_technical_purge_path(): void
    {
        $organization = $this->operator();
        config(['retention.review_policies.'.$organization->id => $this->reviewPolicy()]);
        $this->listing($organization, 'inventory');

        try {
            app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, (string) Str::uuid(),
                new OrganizationAuditData($organization->owner_user_id, 'Fixture only', (string) Str::uuid()));
            $this->fail('A preview must never configure or authorize execution.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('RETENTION_POLICY_REQUIRED', $exception->errorCode());
        }
        $this->assertDatabaseCount('tenant_portability_operations', 0);
        $this->assertModelExists($organization);
    }

    public function test_cli_has_no_execution_option(): void
    {
        $organization = $this->operator();

        $this->expectException(InvalidOptionException::class);
        Artisan::call('organizations:retention-review', [...$this->commandOptions($organization), '--execute' => true]);
    }

    public function test_unreviewed_schema_change_blocks_inventory_instead_of_silently_ignoring_it(): void
    {
        $organization = $this->operator();
        DB::statement('ALTER TABLE people ADD COLUMN retention_fixture_secret TEXT');
        try {
            $this->assertSame(1, Artisan::call('organizations:retention-review', $this->commandOptions($organization)));
            $this->assertStringContainsString('PORTABILITY_CATALOG_INCOMPLETE', Artisan::output());
            $this->assertStringNotContainsString('person_id', Artisan::output());
        } finally {
            DB::statement('ALTER TABLE people DROP COLUMN retention_fixture_secret');
        }
    }

    public function test_same_service_reloads_changed_policy_versions_without_caching_a_previous_deadline(): void
    {
        $organization = $this->operator();
        $person = Person::factory()->create(['organization_id' => $organization->id, 'status' => 'former']);
        PersonDeparture::factory()->create(['organization_id' => $organization->id, 'person_id' => $person->id,
            'departed_at' => '2026-09-01 00:00:00']);
        $service = app(RetentionReviewService::class);
        $audit = new OrganizationAuditData($organization->owner_user_id, 'Fixture', (string) Str::uuid());
        $query = new RetentionReviewQuery('people', CarbonImmutable::parse('2026-10-27', 'UTC'));
        $policy = $this->reviewPolicy();
        config(['retention.review_policies.'.$organization->id => $policy]);
        $this->assertSame('2026-10-01', $service->review($organization->id, $audit, $query)->items[0]['active_review_on']);
        $policy['version'] = 'fixture-v2';
        $policy['rules']['people']['active_days'] = 60;
        config(['retention.review_policies.'.$organization->id => $policy]);

        $report = $service->review($organization->id, $audit, $query);

        $this->assertSame('fixture-v2', $report->policyVersion);
        $this->assertSame('2026-10-31', $report->items[0]['active_review_on']);
    }

    public function test_out_of_range_page_returns_explicit_empty_result_without_losing_total(): void
    {
        $organization = $this->operator();
        Person::factory()->create(['organization_id' => $organization->id]);

        $report = $this->listing($organization, 'people', 2, 1);

        $this->assertSame([], $report['data']);
        $this->assertSame(1, $report['pagination']['total']);
        $this->assertSame('Aucun dossier ne correspond à cet inventaire.', $report['notice']);
    }

    public function test_policy_changed_during_read_is_not_published_under_an_old_version_or_fingerprint(): void
    {
        $organization = $this->operator();
        Person::factory()->create(['organization_id' => $organization->id]);
        $policy = $this->reviewPolicy();
        config(['retention.review_policies.'.$organization->id => $policy]);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $organization, $policy): void {
            if (! $changed && str_contains($query->sql, '"people" as "p"')) {
                $changed = true;
                $policy['rules']['people']['active_days'] = 60;
                config(['retention.review_policies.'.$organization->id => $policy]);
            }
        });

        $this->assertSame(1, Artisan::call('organizations:retention-review', $this->commandOptions($organization)));
        $this->assertTrue($changed);
        $this->assertStringContainsString('RETENTION_POLICY_CHANGED', Artisan::output());
        $this->assertStringNotContainsString('person_id', Artisan::output());
    }

    /** @return array<string, mixed> */
    private function reviewPolicy(): array
    {
        return ['version' => 'fixture-v1', 'reviewed_on' => '2026-10-27', 'responsible_reference' => 'fixture-responsible',
            'validation_reference' => 'fixture-validation', 'reviewed' => true,
            'rules' => ['people' => ['event' => 'recorded_departure', 'active_days' => 30, 'archive_days' => 60, 'final_action' => 'review']]];
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidOptions(): array
    {
        return ['page zero' => ['--page', 0], 'page huge' => ['--page', 10001], 'unbounded page' => ['--per-page', 101],
            'bad date' => ['--as-of', '2026-02-30'], 'unknown section' => ['--section', 'contracts'], 'bad actor' => ['--actor', 0]];
    }

    #[DataProvider('invalidOptions')]
    public function test_cli_validates_before_producing_a_report(string $key, mixed $value): void
    {
        $organization = $this->operator();

        $this->assertSame(1, Artisan::call('organizations:retention-review', [$key => $value, ...array_diff_key($this->commandOptions($organization), [$key => true])]));
        $this->assertStringContainsString('Les options fournies sont invalides', Artisan::output());
    }

    private function operator(): Organization
    {
        $this->freezeTime();
        $organization = Organization::factory()->withMfaOwner()->create();
        config(['retention.review_operators' => [$organization->owner_user_id => [$organization->id]]]);

        return $organization;
    }

    /** @return array<string, mixed> */
    private function commandOptions(Organization $organization): array
    {
        return ['organization' => $organization->id, '--actor' => $organization->owner_user_id, '--reason' => 'Fixture review',
            '--as-of' => '2026-10-27', '--section' => 'people'];
    }

    /** @return array<string, mixed> */
    private function listing(Organization $organization, string $section, int $page = 1, int $perPage = 25): array
    {
        $this->assertSame(0, Artisan::call('organizations:retention-review', [...$this->commandOptions($organization), '--section' => $section, '--page' => $page, '--per-page' => $perPage]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }
}
