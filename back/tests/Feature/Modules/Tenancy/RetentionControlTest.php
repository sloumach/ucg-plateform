<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Application\Services\OrganizationPurgeService;
use App\Modules\Tenancy\Application\Services\OrganizationRecoveryService;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Application\Services\TenantArtifactService;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Modules\Tenancy\Domain\RetentionReviewPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RetentionControlTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake('local');
        config(['filesystems.artifacts' => 'local', 'tenancy.cache_store' => 'database', 'tenancy.schedule_connection' => 'database']);
    }

    public function test_default_is_inspection_only_and_never_claims_qualification(): void
    {
        $organization = $this->operator();
        $result = app(RetentionControlService::class)->run($organization->id, $this->audit($organization))->data;
        $this->assertSame(0, $result['state']['version']);
        $this->assertCount(18, $result['missing_family_rules']);
        $this->assertCount(11, $result['unverified_copies']);
        $this->assertFalse($result['production_qualified']);
        $this->assertFalse($result['business_deletion_supported']);
        $this->assertDatabaseCount('organization_retention_events', 0);
        config(['retention.control_enabled' => false]);
        $this->conflict(fn () => $this->change($organization, 'hold', 0, 'case-A'), 'RETENTION_CONTROL_DISABLED');
        $this->assertDatabaseCount('organization_retention_events', 0);
    }

    /** @return array<string, array{string}> */
    public static function deniedActors(): array
    {
        return ['implicit owner' => ['scope'], 'unverified' => ['email'], 'no MFA' => ['mfa'], 'technical' => ['technical'], 'foreign organization' => ['foreign']];
    }

    #[DataProvider('deniedActors')]
    #[Group('tenant-isolation')]
    public function test_operator_authorization_is_explicit_and_fresh(string $variant): void
    {
        $organization = $this->operator();
        $service = app(RetentionControlService::class);
        $service->run($organization->id, $this->audit($organization));
        match ($variant) {
            'scope' => config(['retention.control_operators' => [], 'retention.review_operators' => [$organization->owner_user_id => [$organization->id]]]),
            'email' => DB::table('users')->where('id', $organization->owner_user_id)->update(['email_verified_at' => null]),
            'mfa' => DB::table('users')->where('id', $organization->owner_user_id)->update(['mfa_confirmed_at' => null]),
            'technical' => PlatformRole::factory()->create(['user_id' => $organization->owner_user_id]),
            'foreign' => config(['retention.control_operators' => [$organization->owner_user_id => [Organization::factory()->withMfaOwner()->create()->id]]]),
            default => throw new \InvalidArgumentException('Unknown authorization fixture.'),
        };
        $this->expectException(AuthorizationException::class);
        $service->run($organization->id, $this->audit($organization), 'hold', 0, 'case-A', $organization->id);
    }

    public function test_independent_holds_preserve_bytes_and_accounting_until_last_explicit_release(): void
    {
        $organization = $this->operator();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid7());
        $files = app(TenantArtifacts::class);
        $artifact = $files->store($context, 'preserve.txt', 'preserve', now()->addMinute()->toDateTimeImmutable());
        $this->change($organization, 'hold', 0, 'case-A');
        $this->change($organization, 'hold', 1, 'case-B');
        $this->travel(2)->minutes();

        $this->conflict(fn () => $files->delete($context, $artifact->id), 'RETENTION_PRESERVATION_ACTIVE');
        $this->assertSame(1, app(TenantArtifactService::class)->expireBatch());
        $this->change($organization, 'release-hold', 2, 'case-A');
        $this->assertSame(['case-B'], app(RetentionControlRepository::class)->state($organization->id)['holds']);
        $this->conflict(fn () => $files->delete($context, $artifact->id), 'RETENTION_PRESERVATION_ACTIVE');
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $organization->id, 'bytes' => 8]);
        Storage::disk('local')->assertExists('tenants/'.$organization->id.'/artifacts/'.$artifact->id);
        $this->change($organization, 'release-hold', 3, 'case-B');
        $this->assertSame(0, app(TenantArtifactService::class)->expireBatch());
        $this->assertDatabaseMissing('tenant_artifacts', ['id' => $artifact->id]);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $organization->id, 'bytes' => 0]);
        $this->assertDatabaseCount('organization_retention_events', 4);
    }

    #[Group('tenant-isolation')]
    public function test_hold_on_one_tenant_does_not_block_another_tenants_expiry(): void
    {
        $first = $this->operator();
        $second = Organization::factory()->withMfaOwner()->create();
        foreach ([$first, $second] as $organization) {
            $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid7());
            app(TenantArtifacts::class)->store($context, 'temporary.txt', 'temporary', now()->addMinute()->toDateTimeImmutable());
        }
        $this->change($first, 'hold', 0, 'case-A');
        $this->travel(2)->minutes();
        $this->assertSame(1, app(TenantArtifactService::class)->expireBatch());
        $this->assertDatabaseHas('tenant_artifacts', ['organization_id' => $first->id]);
        $this->assertDatabaseMissing('tenant_artifacts', ['organization_id' => $second->id]);
    }

    public function test_hold_blocks_export_expiry_and_old_purge_including_settings_only_policy(): void
    {
        $organization = $this->operator();
        $this->archive($organization);
        $export = $this->export($organization);
        $this->change($organization, 'hold', 0, 'case-A');
        config(['tenancy.portability.purge_policies.'.$organization->id => [
            'reference' => 'fixture-only', 'not_before' => now()->subDay()->format('Y-m-d\TH:i:sP'), 'backups_procedure' => 'fixture-only',
            'categories' => ['settings' => 'delete', 'artifacts' => 'retain', 'exports' => 'retain',
                'access_history' => 'retain', 'lifecycle_history' => 'retain', 'organization_identity' => 'retain'],
        ]]);
        $this->conflict(fn () => app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, $export->id, $this->audit($organization)), 'RETENTION_PRESERVATION_ACTIVE');
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id]);
        $this->assertDatabaseMissing('tenant_portability_operations', ['organization_id' => $organization->id, 'kind' => 'purge']);
        $this->travel(8)->days();
        $this->conflict(fn () => app(OrganizationPortabilityService::class)->expire($organization->id, $export->id), 'RETENTION_PRESERVATION_ACTIVE');
        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $export->id, 'status' => 'ready']);
        Storage::disk('local')->assertExists('tenants/'.$organization->id.'/exports/'.$export->id.'.ndjson');
    }

    public function test_idempotent_replay_never_reapplies_a_release_and_stale_versions_are_rejected(): void
    {
        $organization = $this->operator();
        $this->change($organization, 'hold', 0, 'case-A');
        $audit = $this->audit($organization);
        $service = app(RetentionControlService::class);
        $service->run($organization->id, $audit, 'release-hold', 1, 'case-A', $organization->id);
        $this->change($organization, 'hold', 2, 'case-A');
        $result = $service->run($organization->id, $audit, 'release-hold', 1, 'case-A', $organization->id)->data;
        $this->assertTrue($result['replayed']);
        $this->assertSame(['case-A'], $result['state']['holds']);
        $this->assertDatabaseCount('organization_retention_events', 3);
        $this->conflict(fn () => $this->change($organization, 'release-hold', 1, 'case-A'), 'RETENTION_VERSION_CONFLICT');
        $this->conflict(fn () => $service->run($organization->id, $audit, 'hold', 1, 'case-B', $organization->id), 'RETENTION_REQUEST_CONFLICT');
    }

    public function test_restricted_archive_blocks_payload_access_and_recovery_until_explicit_release(): void
    {
        $organization = $this->operator();
        $otherOperator = User::factory()->withMfa()->create();
        $this->conflict(fn () => $this->change($organization, 'restrict-archive', 0, 'archive-review'), 'RETENTION_ARCHIVED_REQUIRED');
        $this->archive($organization);
        $export = $this->export($organization);
        $this->change($organization, 'restrict-archive', 0, 'archive-review');
        try {
            app(OrganizationPortabilityService::class)->read($organization->id, $export->id, new OrganizationAuditData($otherOperator->id, 'Fixture access', (string) Str::uuid7()));
            $this->fail('An undeclared operator must not read restricted archive bytes.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(__('retention.control_forbidden'), $exception->getMessage());
        }
        $this->conflict(fn () => app(OrganizationRecoveryService::class)->recover($organization->id, $organization->id, $export->id, $this->audit($organization)), 'RETENTION_REACTIVATION_BLOCKED');
        $this->change($organization, 'release-archive', 1, 'archive-review-completed');
        app(OrganizationRecoveryService::class)->recover($organization->id, $organization->id, $export->id, $this->audit($organization));
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'suspended']);
    }

    public function test_quarantine_blocks_reactivation_and_incomplete_restoration_receipt(): void
    {
        $organization = $this->operator();
        $this->conflict(fn () => $this->change($organization, 'quarantine', 0, 'restore-A'), 'RETENTION_QUARANTINE_REQUIRES_INACTIVE');
        app(OrganizationService::class)->transition($organization->id, OrganizationStatus::Suspended, $this->audit($organization));
        $this->change($organization, 'quarantine', 0, 'restore-A');
        $this->conflict(fn () => app(OrganizationService::class)->transition($organization->id, OrganizationStatus::Active, $this->audit($organization)), 'RETENTION_REACTIVATION_BLOCKED');
        $this->conflict(fn () => $this->change($organization, 'release-quarantine', 1, 'approval-A'), 'RETENTION_RESTORATION_UNVERIFIED');
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'suspended']);
        $this->assertDatabaseCount('organization_retention_events', 1);
    }

    /** @return array<string, array{string}> */
    public static function restorationVariants(): array
    {
        return ['missing copy' => ['copy'], 'missing family rule' => ['family'], 'stale policy' => ['policy'], 'stale version' => ['version'], 'wrong quarantine' => ['quarantine'], 'extra field' => ['extra'], 'valid receipt' => ['valid'], 'indefinite policy receipt' => ['indefinite']];
    }

    #[DataProvider('restorationVariants')]
    public function test_restoration_release_is_bound_to_all_copy_scopes_policy_and_control_version(string $variant): void
    {
        $organization = $this->operator();
        app(OrganizationService::class)->transition($organization->id, OrganizationStatus::Suspended, $this->audit($organization));
        $this->change($organization, 'quarantine', 0, 'restore-A');
        $rules = [];
        foreach (array_keys(RetentionCatalog::FAMILIES) as $family) {
            $rules[$family] = $variant === 'indefinite'
                ? ['event' => 'manual_review', 'active_days' => null, 'archive_days' => null, 'final_action' => 'preserve']
                : ['event' => 'manual_review', 'active_days' => 30, 'archive_days' => 60, 'final_action' => 'review'];
        }
        $policy = ['version' => 'fixture-only', 'reviewed_on' => now('UTC')->toDateString(), 'responsible_reference' => 'fixture-responsible',
            'validation_reference' => 'fixture-validation', 'reviewed' => true, 'rules' => $rules];
        if ($variant === 'family') {
            unset($policy['rules']['contracts']);
        }
        config(['retention.review_policies.'.$organization->id => $policy]);
        $fingerprint = RetentionReviewPolicy::fromConfiguration($policy, CarbonImmutable::now('UTC')->startOfDay())->fingerprint;
        $approval = ['reviewed' => true, 'reference' => 'approval-A', 'quarantine' => 'restore-A', 'control_version' => 1,
            'policy_fingerprint' => $fingerprint, 'hosting_reference' => 'fixture-hosting', 'copies' => array_fill_keys(RetentionCatalog::UNVERIFIED_COPIES, 'fixture-copy')];
        if ($variant === 'copy') {
            unset($approval['copies']['backups_pitr']);
        } elseif ($variant === 'policy') {
            $approval['policy_fingerprint'] = str_repeat('0', 64);
        } elseif ($variant === 'version') {
            $approval['control_version'] = 0;
        } elseif ($variant === 'quarantine') {
            $approval['quarantine'] = 'restore-B';
        } elseif ($variant === 'extra') {
            $approval['force'] = true;
        }
        config(['retention.restoration_approvals.'.$organization->id => $approval]);
        if (! in_array($variant, ['valid', 'indefinite'], true)) {
            $this->conflict(fn () => $this->change($organization, 'release-quarantine', 1, 'approval-A'), 'RETENTION_RESTORATION_UNVERIFIED');
            $this->assertSame('restore-A', app(RetentionControlRepository::class)->state($organization->id)['quarantine']);

            return;
        }
        $this->change($organization, 'release-quarantine', 1, 'approval-A');
        $this->assertNull(app(RetentionControlRepository::class)->state($organization->id)['quarantine']);
        $event = DB::table('organization_retention_events')->where('organization_id', $organization->id)->where('version', 2)->firstOrFail();
        $evidence = json_decode($event->evidence, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($approval, $evidence);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'suspended']);
        $report = app(RetentionControlService::class)->run($organization->id, $this->audit($organization))->data;
        $this->assertFalse($report['production_qualified']);
        $this->assertFalse($report['business_deletion_supported']);
    }

    public function test_database_prevents_rewriting_deleting_or_reassigning_the_audit(): void
    {
        $organization = $this->operator();
        $this->change($organization, 'hold', 0, 'case-A');
        $other = Organization::factory()->withMfaOwner()->create();
        foreach ([fn () => DB::table('organization_retention_events')->update(['reason' => 'changed']),
            fn () => DB::table('organization_retention_events')->update(['organization_id' => $other->id]),
            fn () => DB::table('organization_retention_events')->delete()] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Retention audit must remain immutable.');
            } catch (QueryException) {
                $this->assertDatabaseHas('organization_retention_events', ['organization_id' => $organization->id, 'reference' => 'case-A']);
            }
        }
    }

    public function test_restricted_archive_rechecks_access_after_buffering_and_closes_the_denied_stream(): void
    {
        $organization = $this->operator();
        $this->archive($organization);
        $export = $this->export($organization);
        $this->change($organization, 'restrict-archive', 0, 'archive-A');
        $archive = app(PortabilityArchive::class);
        $captured = null;
        $this->mock(PortabilityArchive::class)->shouldReceive('read')->once()->andReturnUsing(function ($operation) use ($archive, &$captured) {
            $captured = $archive->read($operation);
            config(['retention.control_operators' => []]);

            return $captured;
        });
        try {
            app(OrganizationPortabilityService::class)->read($organization->id, $export->id, $this->audit($organization));
            $this->fail('Revoked access must prevent delivery.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(__('retention.control_forbidden'), $exception->getMessage());
            $this->assertIsClosedResource($captured);
        }
    }

    public function test_rollback_refuses_to_erase_a_live_hold_or_its_audit(): void
    {
        $organization = $this->operator();
        $this->change($organization, 'hold', 0, 'case-A');
        $migration = require database_path('migrations/2026_10_27_211707_create_organization_retention_events_table.php');
        try {
            $migration->down();
            $this->fail('A populated retention audit must survive rollback.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Preserve retention events', $exception->getMessage());
            $this->assertSame(['case-A'], app(RetentionControlRepository::class)->state($organization->id)['holds']);
        }
    }

    public function test_cli_requires_confirmation_version_and_valid_action_without_mutating_state(): void
    {
        $organization = $this->operator();
        $options = ['organization' => $organization->id, '--actor' => $organization->owner_user_id, '--reason' => 'Fixture CLI'];
        $this->assertSame(0, Artisan::call('organizations:retention-control', $options));
        $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['production_qualified']);
        $this->assertSame(1, Artisan::call('organizations:retention-control', [...$options, '--action' => 'hold', '--reference' => 'case-A']));
        $this->assertDatabaseCount('organization_retention_events', 0);
        $this->assertSame(1, Artisan::call('organizations:retention-control', [...$options, '--action' => 'erase']));
        $this->assertSame(0, Artisan::call('organizations:retention-control', [...$options, '--action' => 'hold', '--reference' => 'case-A',
            '--expected-version' => 0, '--confirm' => $organization->id]));
        $this->assertDatabaseCount('organization_retention_events', 1);
    }

    private function operator(): Organization
    {
        $organization = Organization::factory()->withMfaOwner()->create();
        config(['retention.control_enabled' => true, 'retention.control_operators' => [$organization->owner_user_id => [$organization->id]]]);

        return $organization;
    }

    private function change(Organization $organization, string $action, int $version, string $reference): void
    {
        app(RetentionControlService::class)->run($organization->id, $this->audit($organization), $action, $version, $reference, $organization->id);
    }

    private function audit(Organization $organization): OrganizationAuditData
    {
        return new OrganizationAuditData($organization->owner_user_id, 'Fixture retention decision', (string) Str::uuid7());
    }

    private function archive(Organization $organization): void
    {
        app(OrganizationService::class)->transition($organization->id, OrganizationStatus::Closing, $this->audit($organization));
        app(OrganizationService::class)->transition($organization->id, OrganizationStatus::Archived, $this->audit($organization));
    }

    private function export(Organization $organization): PortabilityOperation
    {
        $operation = app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'reports', '--once' => true, '--sleep' => 0]));
        $result = app(PortabilityRepository::class)->find($organization->id, $operation->id);
        $this->assertSame('ready', $result->status);

        return $result;
    }

    private function conflict(\Closure $operation, string $code): void
    {
        try {
            $operation();
            $this->fail('Expected preservation refusal '.$code);
        } catch (DomainConflictException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }
}
