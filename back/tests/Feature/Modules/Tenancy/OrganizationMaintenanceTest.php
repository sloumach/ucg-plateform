<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\ArtifactStorageException;
use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Application\Services\OrganizationPurgeService;
use App\Modules\Tenancy\Application\Services\OrganizationRecoveryService;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationMaintenanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.cache_store' => 'database', 'tenancy.schedule_connection' => 'database', 'filesystems.artifacts' => 'local']);
    }

    public function test_default_purge_is_read_only_and_execute_without_policy_preserves_everything(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $file = app(TenantArtifacts::class)->store($context, 'keep.txt', 'keep');
        $this->archive($organization);
        $export = $this->export($organization);
        $count = app(PortabilityRepository::class)->counts($organization->id);

        $this->assertSame(0, Artisan::call('organizations:portability', ['organization' => $organization->id,
            'action' => 'purge', '--actor' => $organization->owner_user_id, '--reason' => 'Read-only plan']));
        $this->assertSame(1, Artisan::call('organizations:portability', ['organization' => $organization->id,
            'action' => 'purge', '--actor' => $organization->owner_user_id, '--reason' => 'No policy',
            '--execute' => true, '--confirm' => $organization->id, '--source-export' => $export->id]));

        $this->assertStringContainsString('RETENTION_POLICY_REQUIRED', Artisan::output());
        $this->assertSame($count, app(PortabilityRepository::class)->counts($organization->id));
        Storage::disk('local')->assertExists(TenantResourceNamespace::path($organization->id, 'artifacts/'.$file->id));
        Storage::disk('local')->assertExists($this->exportPath($export));
    }

    public function test_reviewed_targeted_purge_deletes_only_allowed_categories_and_keeps_global_accounts_and_histories(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $owner = User::factory()->withMfa()->create();
        $first = Organization::factory()->withMfaOwner()->recycle($owner)->create();
        $second = Organization::factory()->withMfaOwner()->recycle($owner)->create();
        OrganizationMembership::factory()->withMfaAccount()->for($first)->create();
        $resolver = app(TenantResolver::class);
        $files = app(TenantArtifacts::class);
        $a = $files->store($resolver->resolve($first->id, '', $owner->id, (string) Str::uuid()), 'same.txt', 'first');
        $b = $files->store($resolver->resolve($second->id, '', $owner->id, (string) Str::uuid()), 'same.txt', 'second');
        $this->archive($first);
        $firstExport = $this->export($first);
        $secondExport = $this->export($second);
        $history = DB::table('organization_lifecycle_events')->where('organization_id', $first->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->policy($first);

        $operation = app(OrganizationPurgeService::class)->execute($first->id, $first->id, $firstExport->id, $this->audit($first));
        $repeated = app(OrganizationPurgeService::class)->execute($first->id, $first->id, $firstExport->id, $this->audit($first), $operation->id);

        $this->assertSame('completed', $operation->status);
        $this->assertSame($operation->id, $repeated->id);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $first->id, 'bytes' => 0]);
        $this->assertDatabaseMissing('tenant_artifacts', ['id' => $a->id]);
        $this->assertDatabaseMissing('organization_settings', ['organization_id' => $first->id]);
        $this->assertDatabaseHas('organizations', ['id' => $first->id, 'status' => 'archived']);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $second->id]);
        $this->assertDatabaseHas('tenant_artifacts', ['id' => $b->id, 'organization_id' => $second->id]);
        $this->assertModelExists($owner);
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertSame($history, DB::table('organization_lifecycle_events')->where('organization_id', $first->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        Storage::disk('local')->assertMissing(TenantResourceNamespace::path($first->id, 'artifacts/'.$a->id));
        Storage::disk('local')->assertMissing($this->exportPath($firstExport));
        Storage::disk('local')->assertExists($this->exportPath($secondExport));
        $this->assertSame('second', Storage::disk('local')->get(TenantResourceNamespace::path($second->id, 'artifacts/'.$b->id)));
    }

    /** @return array<string, array{string, string}> */
    public static function forbiddenPolicies(): array
    {
        return ['future date' => ['future', 'RETENTION_PERIOD_PENDING'],
            'protected history' => ['history', 'RETENTION_POLICY_REQUIRED'],
            'missing category' => ['missing', 'RETENTION_POLICY_REQUIRED'],
            'unexpected option' => ['unexpected', 'RETENTION_POLICY_REQUIRED'],
            'invalid calendar date' => ['invalid_date', 'RETENTION_POLICY_REQUIRED']];
    }

    #[DataProvider('forbiddenPolicies')]
    public function test_inadmissible_retention_policy_never_creates_a_purge_operation(string $variant, string $code): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $this->archive($organization);
        $export = $this->export($organization);
        $policy = $this->policy($organization);
        if ($variant === 'future') {
            $policy['not_before'] = now()->addDay()->format('Y-m-d\TH:i:sP');
        } elseif ($variant === 'history') {
            $policy['categories']['access_history'] = 'delete';
        } elseif ($variant === 'missing') {
            unset($policy['categories']['exports']);
        } elseif ($variant === 'invalid_date') {
            $policy['not_before'] = '2025-02-30T00:00:00+00:00';
        } else {
            $policy['force'] = true;
        }
        config(['tenancy.portability.purge_policies.'.$organization->id => $policy]);

        try {
            app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, $export->id, $this->audit($organization));
            $this->fail('An inadmissible policy must never authorize deletion.');
        } catch (DomainConflictException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }

        $this->assertDatabaseMissing('tenant_portability_operations', ['organization_id' => $organization->id, 'kind' => 'purge']);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id]);
        Storage::disk('local')->assertExists($this->exportPath($export));
    }

    public function test_purge_requires_matching_confirmation_and_archived_status(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $export = $this->export($organization);
        $this->policy($organization);

        try {
            app(OrganizationPurgeService::class)->execute($organization->id, '', $export->id, $this->audit($organization));
            $this->fail('Missing target confirmation must be refused.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PORTABILITY_CONFIRMATION_REQUIRED', $exception->errorCode());
        }
        try {
            app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, $export->id, $this->audit($organization));
            $this->fail('Active tenant must not be purged.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PURGE_ARCHIVED_REQUIRED', $exception->errorCode());
        }

        $this->assertDatabaseCount('tenant_portability_operations', 1);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'active']);
    }

    public function test_recovery_returns_to_suspended_with_audit_without_rewriting_data_or_touching_other_tenant(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $other = Organization::factory()->withMfaOwner()->create();
        $file = app(TenantArtifacts::class)->store(app(TenantResolver::class)->resolve($organization->id, '',
            $organization->owner_user_id, (string) Str::uuid()), 'preserved.txt', 'unchanged');
        OrganizationMembership::factory()->withMfaAccount()->for($organization)->create();
        $this->archive($organization);
        $export = $this->export($organization);
        $memberships = DB::table('organization_memberships')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $operation = app(OrganizationRecoveryService::class)->recover($organization->id, $organization->id, $export->id, $this->audit($organization));

        $this->assertSame('completed', $operation->status);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('organizations', ['id' => $other->id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_lifecycle_events', ['organization_id' => $organization->id, 'from_status' => 'archived', 'to_status' => 'suspended']);
        $this->assertSame($memberships, DB::table('organization_memberships')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('organization_settings', 2);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('unchanged', Storage::disk('local')->get(TenantResourceNamespace::path($organization->id, 'artifacts/'.$file->id)));
    }

    public function test_export_generated_before_archiving_cannot_authorize_purge(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $export = $this->export($organization);
        $this->archive($organization);
        $this->policy($organization);

        try {
            app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, $export->id, $this->audit($organization));
            $this->fail('A pre-archive snapshot must not authorize deletion.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PURGE_ARCHIVED_EXPORT_REQUIRED', $exception->errorCode());
        }
        $this->assertDatabaseMissing('tenant_portability_operations', ['kind' => 'purge']);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id]);
        Storage::disk('local')->assertExists($this->exportPath($export));
    }

    public function test_purge_resumes_the_same_bounded_operation_and_refuses_policy_drift_or_new_exports(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        for ($index = 0; $index < 101; $index++) {
            app(TenantArtifacts::class)->store($context, 'file.txt', 'x');
        }
        $this->archive($organization);
        $export = $this->export($organization);
        $policy = $this->policy($organization);
        $service = app(OrganizationPurgeService::class);

        $operation = $service->execute($organization->id, $organization->id, $export->id, $this->audit($organization));

        $this->assertSame('pending', $operation->status);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $organization->id, 'bytes' => 1]);
        try {
            app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
            $this->fail('New exports must not race an ongoing or completed purge.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PURGE_ALREADY_STARTED', $exception->errorCode());
        }
        config(['tenancy.portability.purge_policies.'.$organization->id.'.reference' => 'changed']);
        try {
            $service->execute($organization->id, $organization->id, $export->id, $this->audit($organization), $operation->id);
            $this->fail('A resumed purge must retain its reviewed policy.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PURGE_POLICY_CHANGED', $exception->errorCode());
        }
        $this->assertDatabaseCount('tenant_artifacts', 1);
        config(['tenancy.portability.purge_policies.'.$organization->id => $policy]);
        $completed = $service->execute($organization->id, $organization->id, $export->id, $this->audit($organization), $operation->id);

        $this->assertSame($operation->id, $completed->id);
        $this->assertSame('completed', $completed->status);
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->assertDatabaseCount('tenant_portability_operations', 2);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $organization->id, 'bytes' => 0]);
    }

    public function test_external_deletion_failure_keeps_accounting_and_can_resume_after_storage_recovers(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $file = app(TenantArtifacts::class)->store(app(TenantResolver::class)->resolve($organization->id, '',
            $organization->owner_user_id, (string) Str::uuid()), 'keep.txt', 'keep');
        $this->archive($organization);
        $export = $this->export($organization);
        $this->policy($organization);
        $original = app(PortabilityArchive::class);
        $archive = $this->mock(PortabilityArchive::class);
        $archive->shouldReceive('read')->once()->andReturnUsing(fn ($op) => $original->read($op));
        $archive->shouldReceive('deleteArtifact')->once()->andThrow(new ArtifactStorageException('Storage unavailable'));

        try {
            app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, $export->id, $this->audit($organization));
            $this->fail('A failed external deletion must not release its database reservation.');
        } catch (ArtifactStorageException) {
            $this->assertDatabaseHas('tenant_artifacts', ['id' => $file->id]);
            $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $organization->id, 'bytes' => 4]);
            $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id]);
            $this->assertSame('keep', Storage::disk('local')->get(TenantResourceNamespace::path($organization->id, 'artifacts/'.$file->id)));
        }
        $pending = DB::table('tenant_portability_operations')->where('organization_id', $organization->id)->where('kind', 'purge')->sole();
        $this->assertSame('pending', $pending->status);
        $this->app->instance(PortabilityArchive::class, $original);

        $result = app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, $export->id, $this->audit($organization), (string) $pending->id);

        $this->assertSame('completed', $result->status);
        $this->assertDatabaseMissing('tenant_artifacts', ['id' => $file->id]);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $organization->id, 'bytes' => 0]);
        Storage::disk('local')->assertMissing(TenantResourceNamespace::path($organization->id, 'artifacts/'.$file->id));
    }

    public function test_recovery_refuses_owner_exclusivity_conflict_without_status_or_audit_change(): void
    {
        Storage::fake('local');
        $owner = User::factory()->withMfa()->create();
        $organization = Organization::factory()->withMfaOwner()->recycle($owner)->create();
        $this->archive($organization);
        Organization::factory()->withMfaOwner()->recycle($owner)->create();
        $export = $this->export($organization);
        config(['tenancy.allow_multiple_organizations' => false]);

        try {
            app(OrganizationRecoveryService::class)->recover($organization->id, $organization->id, $export->id, $this->audit($organization));
            $this->fail('Owner exclusivity conflict must block recovery.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('MULTIPLE_ORGANIZATIONS_FORBIDDEN', $exception->errorCode());
        }

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'archived']);
        $this->assertDatabaseMissing('tenant_portability_operations', ['kind' => 'recovery']);
        $this->assertDatabaseCount('organization_lifecycle_events', 2);
    }

    public function test_recovery_refuses_an_organization_after_any_purge_has_started(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $this->archive($organization);
        $export = $this->export($organization);
        $policy = $this->policy($organization);
        $policy['categories']['exports'] = 'retain';
        config(['tenancy.portability.purge_policies.'.$organization->id => $policy]);
        app(OrganizationPurgeService::class)->execute($organization->id, $organization->id, $export->id, $this->audit($organization));

        $this->expectException(DomainConflictException::class);
        app(OrganizationRecoveryService::class)->recover($organization->id, $organization->id, $export->id, $this->audit($organization));
    }

    public function test_operation_identity_and_history_remain_protected_by_database(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $other = Organization::factory()->withMfaOwner()->create();
        $export = $this->export($organization);
        foreach ([
            fn () => DB::table('tenant_portability_operations')->where('id', $export->id)->update(['organization_id' => $other->id]),
            fn () => DB::table('tenant_portability_operations')->where('id', $export->id)->update(['reason' => 'Rewrite']),
            fn () => DB::table('tenant_portability_operations')->where('id', $export->id)->delete(),
            fn () => DB::table('tenant_portability_operations')->where('id', $export->id)->update(['bytes' => -1]),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Protected maintenance metadata must not be rewritten or deleted.');
            } catch (QueryException) {
                $this->assertDatabaseHas('tenant_portability_operations', ['id' => $export->id, 'organization_id' => $organization->id, 'reason' => 'Test maintenance']);
            }
        }
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

    /** @return array{reference: string, not_before: string, backups_procedure: string, categories: array<string, string>} */
    private function policy(Organization $organization): array
    {
        $policy = ['reference' => 'TEST-ONLY-approved-policy', 'not_before' => now()->subDay()->format('Y-m-d\TH:i:sP'),
            'backups_procedure' => 'TEST-ONLY-no-real-backups',
            'categories' => ['settings' => 'delete', 'artifacts' => 'delete', 'exports' => 'delete',
                'access_history' => 'retain', 'lifecycle_history' => 'retain', 'organization_identity' => 'retain']];
        config(['tenancy.portability.purge_policies.'.$organization->id => $policy]);

        return $policy;
    }

    private function audit(Organization $organization): OrganizationAuditData
    {
        return new OrganizationAuditData($organization->owner_user_id, 'Test maintenance', (string) Str::uuid());
    }

    private function exportPath(PortabilityOperation $operation): string
    {
        return TenantResourceNamespace::path($operation->organizationId, 'exports/'.$operation->id.'.ndjson');
    }
}
