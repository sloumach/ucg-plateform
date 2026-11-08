<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class SponsorshipContextTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    private const array EDITOR = ['partnerships.read', 'partnerships.write', 'partnerships.validate', 'financial.read', 'financial.write', 'financial.validate', 'calendar.read', 'media.read'];

    /** @return array<string, array{string}> */
    public static function sources(): array
    {
        return ['event' => ['event'], 'content' => ['content']];
    }

    #[DataProvider('sources')]
    public function test_paginated_references_exclude_private_foreign_and_delayed_sources_and_return_no_url(string $type): void
    {
        [$org, $user, , $source] = $this->scenario($type);
        $private = $this->source($org, $user, $type);
        $private->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null])->save();
        $this->source(Organization::factory()->create(), $user, $type);
        if ($type === 'content') {
            $this->source($org, $user, $type)->forceFill(['publish_at' => now()->addDay()])->save();
        }
        $this->getJson($this->references($org, $type).'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $source->id)->assertJsonPath('data.0.version', $source->version)
            ->assertJsonMissingPath('data.0.url')->assertJsonMissingPath('data.0.description');
    }

    #[DataProvider('sources')]
    public function test_context_is_immutable_and_approval_requires_a_fresh_source_version_and_an_independent_actor(string $type): void
    {
        [$org, $user, $agreement, $source] = $this->scenario($type);
        $root = $this->root($agreement);
        $scope = $this->postJson($root.'/exposures', $this->input($agreement, $source, $type))->assertCreated()
            ->assertJsonPath('data.target_type', $type)->assertJsonPath('data.target_id', $source->id)->assertJsonPath('data.target_current', true)->json('data');
        $financial = $this->patchJson($root.'/financial', ['agreement_version' => $scope['agreement_version'], 'expected_version' => null,
            'terms' => ['kind' => 'cash', 'currency' => 'EUR', 'amount' => '100.00', 'in_kind' => null, 'payment_terms' => null]])->assertOk()->json('data');
        $body = ['version' => $financial['agreement_version'], 'target_status' => 'approved', 'confirm_authorizations' => true];
        $this->postJson($root.'/transitions', $body)->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_SELF_APPROVAL_FORBIDDEN');
        $this->member($org);
        $approved = $this->postJson($root.'/transitions', $body)->assertOk()->json('data');
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', true);
        $source->forceFill(['title' => 'Référence révisée', 'version' => (string) Str::uuid()])->save();
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', false)->assertJsonPath('data.0.target_current', false);
        $this->actingAsWithMfa($user);
        $patch = ['agreement_version' => $approved['version'], 'version' => $scope['version'], 'target_version' => $source->version];
        $this->patchJson($root.'/exposures/'.$scope['id'], [...$patch, 'target_id' => (string) Str::uuid()])->assertUnprocessable();
        $changed = $this->patchJson($root.'/exposures/'.$scope['id'], $patch)->assertOk()
            ->assertJsonPath('data.target_label', 'Référence révisée')->assertJsonPath('data.target_current', true)->json('data');
        $this->assertDatabaseHas('sponsorship_agreements', ['id' => $agreement->id, 'status' => 'draft', 'approved_by' => null]);
        $this->assertDatabaseHas('sponsorship_exposure_contexts', ['exposure_id' => $scope['id'], 'source_version' => $source->version]);
        $this->assertNotSame($approved['version'], $changed['agreement_version']);
    }

    #[DataProvider('sources')]
    #[Group('tenant-isolation')]
    public function test_foreign_missing_and_stale_targets_never_create_or_invalidate_an_exposure(string $type): void
    {
        [$org, $user, $agreement, $source] = $this->scenario($type);
        $root = $this->root($agreement).'/exposures';
        $body = $this->input($agreement, $source, $type);
        $foreign = $this->source(Organization::factory()->create(), $user, $type);
        $this->postJson($root, [...$body, 'target_id' => $foreign->id, 'target_version' => $foreign->version])->assertNotFound();
        unset($body['target_version']);
        $this->postJson($root, $body)->assertUnprocessable()->assertJsonValidationErrors('target_version');
        $this->postJson($root, [...$body, 'target_version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_TARGET_VERSION_STALE');
        $this->assertDatabaseCount('sponsorship_exposure_contexts', 0);
        $this->assertDatabaseCount('sponsorship_exposures', 0);
        $this->assertDatabaseHas('sponsorship_agreements', ['id' => $agreement->id, 'version' => $agreement->version]);
    }

    #[DataProvider('sources')]
    #[Group('tenant-isolation')]
    public function test_source_permission_revocation_hides_scope_deliverable_history_and_counts(string $type): void
    {
        [$org, $user, $agreement, $source] = $this->scenario($type);
        $root = $this->root($agreement);
        $scope = $this->postJson($root.'/exposures', $this->input($agreement, $source, $type))->assertCreated()->json('data');
        $agreement->refresh();
        $campaign = $this->postJson($root.'/campaigns', ['agreement_version' => $agreement->version, 'title' => 'Campagne privée',
            'starts_on' => today()->subDay()->format('Y-m-d'), 'ends_on' => today()->addWeek()->format('Y-m-d'), 'responsible_user_id' => $user->id])->assertCreated()->json('data');
        $path = $root.'/campaigns/'.$campaign['id'].'/deliverables';
        $promise = $this->postJson($path, ['agreement_version' => $agreement->version, 'campaign_version' => $campaign['version'], 'exposure_id' => $scope['id'],
            'title' => 'Preuve confidentielle liée', 'kind' => $type === 'event' ? 'event_presence' : 'video', 'responsible_user_id' => $user->id,
            'due_at' => now()->subHour()->toIso8601String()])->assertCreated()->json('data');
        $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 1);
        OrganizationRole::query()->where('organization_id', $org->id)->update(['permissions' => json_encode(array_values(array_diff(self::EDITOR, ['calendar.read', 'media.read'])), JSON_THROW_ON_ERROR)]);
        $this->getJson($this->references($org, $type))->assertForbidden();
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($path)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($root.'/campaigns/'.$campaign['id'].'/history')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/tenants/'.$org->id.'/sponsorship-dashboard')->assertOk()->assertJsonPath('summary.overdue', 0);
        $this->postJson($path.'/'.$promise['id'].'/deliveries', ['agreement_version' => $agreement->version, 'campaign_version' => $promise['campaign_version'], 'version' => $promise['version'],
            'delivered_at' => now()->toIso8601String(), 'proof_reference' => 'REFUSE'])->assertNotFound();
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $promise['id'], 'status' => 'promised']);
    }

    #[DataProvider('sources')]
    public function test_private_source_is_hidden_even_from_partnership_editors(string $type): void
    {
        [, , $agreement, $source] = $this->scenario($type);
        $scope = $this->postJson($this->root($agreement).'/exposures', $this->input($agreement, $source, $type))->assertCreated()->json('data');
        $source->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'version' => (string) Str::uuid()])->save();
        $this->getJson($this->root($agreement).'/exposures')->assertOk()->assertJsonPath('meta.total', 0);
        $this->patchJson($this->root($agreement).'/exposures/'.$scope['id'], ['agreement_version' => $scope['agreement_version'], 'version' => $scope['version'], 'target_version' => $source->version])->assertNotFound();
    }

    #[DataProvider('sources')]
    public function test_validated_deliverable_loses_effective_authorization_when_its_source_changes(string $type): void
    {
        [$org, $user, $agreement, $source] = $this->scenario($type);
        $root = $this->root($agreement);
        $scope = $this->postJson($root.'/exposures', $this->input($agreement, $source, $type))->assertCreated()->json('data');
        $terms = $this->patchJson($root.'/financial', ['agreement_version' => $scope['agreement_version'], 'expected_version' => null,
            'terms' => ['kind' => 'cash', 'currency' => 'EUR', 'amount' => '100.00', 'in_kind' => null, 'payment_terms' => null]])->assertOk()->json('data');
        $this->member($org);
        $approved = $this->postJson($root.'/transitions', ['version' => $terms['agreement_version'], 'target_status' => 'approved', 'confirm_authorizations' => true])->assertOk()->json('data');
        $this->actingAsWithMfa($user);
        $campaign = $this->postJson($root.'/campaigns', ['agreement_version' => $approved['version'], 'title' => 'Campagne validée',
            'starts_on' => today()->subDay()->format('Y-m-d'), 'ends_on' => today()->addWeek()->format('Y-m-d'), 'responsible_user_id' => $user->id])->assertCreated()->json('data');
        $path = $root.'/campaigns/'.$campaign['id'].'/deliverables';
        $promise = $this->postJson($path, ['agreement_version' => $approved['version'], 'campaign_version' => $campaign['version'], 'exposure_id' => $scope['id'],
            'title' => 'Présence déclarée', 'kind' => 'brand_placement', 'responsible_user_id' => $user->id, 'due_at' => now()->toIso8601String()])->assertCreated()->json('data');
        $delivered = $this->postJson($path.'/'.$promise['id'].'/deliveries', ['agreement_version' => $approved['version'], 'campaign_version' => $promise['campaign_version'], 'version' => $promise['version'],
            'delivered_at' => now()->toIso8601String(), 'proof_reference' => 'Vérification manuelle, pas de publication automatique'])->assertOk()->json('data');
        $this->member($org);
        $this->postJson($path.'/'.$promise['id'].'/transitions', ['agreement_version' => $approved['version'], 'campaign_version' => $delivered['campaign_version'], 'version' => $delivered['version'],
            'target_status' => 'validated', 'confirm_evidence' => true])->assertOk()->assertJsonPath('data.validation_matches_agreement', true);
        $source->forceFill(['version' => (string) Str::uuid()])->save();
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.status', 'validated')->assertJsonPath('data.0.validation_matches_agreement', false)->assertJsonPath('data.0.exposure_authorized', false);
    }

    #[DataProvider('sources')]
    public function test_reference_queries_remain_constant_for_a_page_of_multiple_contextual_exposures(string $type): void
    {
        [, , $agreement, $source] = $this->scenario($type);
        $root = $this->root($agreement);
        for ($index = 0; $index < 5; $index++) {
            $agreement->refresh();
            $this->postJson($root.'/exposures', $this->input($agreement, $source, $type))->assertCreated();
        }
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->getJson($root.'/exposures?per_page=1')->assertOk()->assertJsonCount(1, 'data');
            $one = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->getJson($root.'/exposures?per_page=5')->assertOk()->assertJsonCount(5, 'data');
            $this->assertSame($one, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_empty_migration_round_trip_retains_the_catalog_and_sql_guards(): void
    {
        $migration = require database_path('migrations/2026_11_08_080532_create_sponsorship_exposure_contexts_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('sponsorship_exposure_contexts'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        [$org, , $agreement, $source] = $this->scenario('event');
        $scope = $this->postJson($this->root($agreement).'/exposures', $this->input($agreement, $source, 'event'))->assertCreated()->json('data');
        $foreign = CalendarActivity::factory()->create();
        $this->reject(fn () => DB::table('sponsorship_exposure_contexts')->where('exposure_id', $scope['id'])->update(['activity_id' => $foreign->id]));
        $this->reject(fn () => DB::table('sponsorship_exposure_contexts')->where('exposure_id', $scope['id'])->delete());
        $this->reject(fn () => DB::table('sponsorship_exposure_contexts')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org->id,
            'exposure_id' => $scope['id'], 'activity_id' => $foreign->id, 'source_version' => $foreign->version, 'created_at' => now()]));
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_audit_failure_rolls_back_parent_exposure_and_context_together(): void
    {
        [, , $agreement, $source] = $this->scenario('event');
        $journal = $this->mock(TenantAuditJournal::class);
        $journal->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->root($agreement).'/exposures', $this->input($agreement, $source, 'event'))->assertStatus(500);
        $this->assertDatabaseCount('sponsorship_exposures', 0);
        $this->assertDatabaseCount('sponsorship_exposure_contexts', 0);
        $this->assertDatabaseHas('sponsorship_agreements', ['id' => $agreement->id, 'version' => $agreement->version]);
    }

    public function test_a_media_source_inherits_the_current_restrictions_of_its_calendar_context(): void
    {
        [$org, $user, $agreement, $item] = $this->scenario('content');
        $event = CalendarActivity::factory()->shared()->create(['organization_id' => $org->id, 'created_by' => $user->id]);
        app(MediaRepository::class)->bindContext($item, 'activity_id', $event->id);
        $this->postJson($this->root($agreement).'/exposures', $this->input($agreement, $item, 'content'))->assertCreated();
        $event->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null])->save();
        $this->getJson($this->references($org, 'content'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($agreement).'/exposures')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_sql_requires_exactly_one_source_and_a_composite_tenant_reference(): void
    {
        [$org, , $agreement, $source] = $this->scenario('event');
        $scope = app(SponsorshipAgreementRepository::class)->saveExposure(null, ['organization_id' => $org->id, 'agreement_id' => $agreement->id,
            'target_type' => 'organization', 'target_label' => 'Cible', 'channel' => 'event', 'authorization_reference' => 'Fixture', 'status' => 'active', 'version' => (string) Str::uuid()]);
        $foreign = CalendarActivity::factory()->create();
        $row = ['id' => (string) Str::uuid(), 'organization_id' => $org->id, 'exposure_id' => $scope->id, 'source_version' => $source->version, 'created_at' => now()];
        $this->reject(fn () => DB::table('sponsorship_exposure_contexts')->insert($row));
        $this->reject(fn () => DB::table('sponsorship_exposure_contexts')->insert([...$row, 'activity_id' => $source->id, 'media_id' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('sponsorship_exposure_contexts')->insert([...$row, 'activity_id' => $foreign->id]));
        $this->assertDatabaseCount('sponsorship_exposure_contexts', 0);
    }

    public function test_a_preservation_hold_blocks_context_binding_in_sql(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $agreement = SponsorshipAgreement::factory()->create(['sponsor_id' => $sponsor->id]);
        $event = CalendarActivity::factory()->create(['organization_id' => $org->id]);
        $records = app(SponsorshipAgreementRepository::class);
        $scope = $records->saveExposure(null, ['organization_id' => $org->id, 'agreement_id' => $agreement->id,
            'target_type' => 'organization', 'target_label' => 'Cible', 'channel' => 'event', 'authorization_reference' => 'Fixture', 'status' => 'active', 'version' => (string) Str::uuid()]);
        config(['retention.control_enabled' => true, 'retention.control_operators' => [$org->owner_user_id => [$org->id]]]);
        app(RetentionControlService::class)->run($org->id, new OrganizationAuditData($org->owner_user_id, 'Fixture sponsor preservation', (string) Str::uuid7()), 'hold', 0, 'sponsor-hold', $org->id);
        $this->reject(fn () => $records->saveContext($scope, 'event', $event->id, $event->version));
        $this->assertDatabaseCount('sponsorship_exposure_contexts', 0);
    }

    /** @return array{Organization, User, SponsorshipAgreement, ($type is 'event' ? CalendarActivity : MediaItem)} */
    private function scenario(string $type): array
    {
        $this->freezeTime();
        $org = Organization::factory()->create();
        $user = $this->member($org);
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $agreement = SponsorshipAgreement::factory()->create(['organization_id' => $org->id, 'sponsor_id' => $sponsor->id,
            'prepared_by' => $user->id, 'responsible_user_id' => $user->id, 'starts_on' => today()->subWeek(), 'ends_on' => today()->addMonth()]);

        return [$org, $user, $agreement, $this->source($org, $user, $type)];
    }

    private function member(Organization $org): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, self::EDITOR);
        $this->actingAsWithMfa($user);

        return $user;
    }

    /** @return ($type is 'event' ? CalendarActivity : MediaItem) */
    private function source(Organization $org, User $user, string $type): CalendarActivity|MediaItem
    {
        $values = ['organization_id' => $org->id, 'created_by' => $user->id, 'title' => '<script>Référence sponsor</script>', 'visibility' => 'organization',
            'sharing_authorized_by' => $user->id, 'sharing_authorized_at' => now()];

        return $type === 'event' ? CalendarActivity::factory()->create($values)
            : MediaItem::factory()->create([...$values, 'team_id' => Team::factory()->create(['organization_id' => $org->id])->id]);
    }

    private function references(Organization $org, string $type): string
    {
        return '/api/v1/tenants/'.$org->id.'/sponsorship-references/'.($type === 'event' ? 'events' : 'contents');
    }

    private function root(SponsorshipAgreement $agreement): string
    {
        return '/api/v1/tenants/'.$agreement->organization_id.'/sponsors/'.$agreement->sponsor_id.'/agreements/'.$agreement->id;
    }

    /** @return array<string, mixed> */
    private function input(SponsorshipAgreement $agreement, CalendarActivity|MediaItem $source, string $type): array
    {
        return ['agreement_version' => $agreement->version, 'target_type' => $type, 'target_id' => $source->id, 'target_version' => $source->version,
            'channel' => 'event', 'authorization_reference' => 'Autorisation explicite vérifiée'];
    }

    /** @param \Closure(): mixed $operation */
    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Invalid exposure context must be refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

    }
}
