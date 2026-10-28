<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\ErasureAccessGate;
use App\Modules\Tenancy\Application\Services\ErasureReconciliationService;
use App\Modules\Tenancy\Application\Services\ManualErasureService;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Application\Services\TenantJobRunner;
use App\Modules\Tenancy\Domain\Contracts\ArtifactLedger;
use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Modules\Tenancy\Domain\Exceptions\TenantExecutionRejectedException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Support\Queue\JobContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ErasureReconciliationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['database.connections.erasure_restore' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
            'retention.erasure.journal_connection' => 'erasure_restore', 'retention.erasure.journal_independence_reviewed' => true,
            'retention.erasure.installation_id' => (string) Str::uuid7(), 'retention.erasure.journal_key' => str_repeat('independent-restore-secret-', 4),
            'retention.erasure.enabled' => true, 'retention.control_enabled' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('erasure_restore');
        parent::tearDown();
    }

    public function test_old_source_backup_cannot_reopen_access_and_person_erasure_is_replayed(): void
    {
        [$org, $person] = $this->scenario('active');
        DB::beginTransaction(); // Source-only snapshot; the journal uses an independent connection.
        DB::table('organizations')->where('id', $org->id)->update(['status' => 'suspended']);
        $this->approveAndComplete($org, 'person', $person->id);
        $this->assertDatabaseMissing('people', ['id' => $person->id]);
        DB::rollBack();
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertDatabaseCount('tenant_erasure_operations', 0);
        $this->assertCount(1, app(ErasureJournal::class)->intents($org->id, 0, 1000));
        $this->blocked(fn () => app(ErasureAccessGate::class)->assertReadable($org->id), 'ERASURE_RESTORE_BLOCKED');
        $this->blocked(fn () => app(OrganizationPortabilityService::class)->requestExport($org->id, $this->audit($org)), 'ERASURE_RESTORE_BLOCKED');
        $ran = false;
        try {
            app(TenantJobRunner::class)->scheduled(new JobContext((string) Str::uuid7(), $org->id), function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('The scheduler must check the independent journal before running business work.');
        } catch (TenantExecutionRejectedException $exception) {
            $this->assertSame('ERASURE_RESTORE_BLOCKED', $exception->reason);
        }
        $this->assertFalse($ran);
        config(['retention.erasure.restore_mode' => true, 'retention.erasure.restore_epoch' => (string) Str::uuid7()]);
        $head = app(ErasureJournal::class)->head($org->id);
        $service = app(ErasureReconciliationService::class);
        $result = $service->run($org->id, $this->audit($org), $head, $org->id);
        $operation = $result->data['operation'];
        $this->assertNotNull($operation['source_intent_id']);
        $this->complete($org, $operation['id']);
        $result = $service->run($org->id, $this->audit($org), $head, $org->id);
        $this->assertTrue($result->data['reconciled']);
        $this->assertDatabaseMissing('people', ['id' => $person->id]);
        config(['retention.erasure.restore_mode' => false]);
        app(ErasureAccessGate::class)->assertReadable($org->id);
        $this->assertNull(app(RetentionControlRepository::class)->state($org->id)['quarantine']);
    }

    public function test_restore_removes_journaled_orphan_file_even_when_old_sql_has_no_metadata(): void
    {
        [$org] = $this->scenario('archived');
        DB::beginTransaction();
        $artifact = app(ArtifactLedger::class)->reserve($org->id, 'Private fixture.txt', 7, 'local', 1000);
        $path = 'tenants/'.$org->id.'/artifacts/'.$artifact['id'];
        Storage::disk('local')->put($path, 'private');
        $this->approveAndComplete($org, 'organization', $org->id);
        Storage::disk('local')->assertMissing($path);
        DB::rollBack();
        $this->assertDatabaseMissing('tenant_artifacts', ['id' => $artifact['id']]);
        Storage::disk('local')->put($path, 'private');
        config(['retention.erasure.restore_mode' => true, 'retention.erasure.restore_epoch' => (string) Str::uuid7()]);
        $head = app(ErasureJournal::class)->head($org->id);
        $result = app(ErasureReconciliationService::class)->run($org->id, $this->audit($org), $head, $org->id);
        $id = $result->data['operation']['id'];
        $this->complete($org, $id);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('erasure:'.$id, app(RetentionControlRepository::class)->state($org->id)['quarantine']);
    }

    public function test_journal_is_append_only_and_invalid_head_cannot_authorize_a_replay(): void
    {
        [$org, $person] = $this->scenario();
        $this->approveAndComplete($org, 'person', $person->id);
        try {
            DB::connection('erasure_restore')->transaction(fn () => DB::connection('erasure_restore')->table('retention_erasure_journal')->delete());
            $this->fail('Independent decisions must not be deleted.');
        } catch (QueryException) {
            $this->assertCount(1, app(ErasureJournal::class)->intents($org->id, 0, 1000));
        }
        config(['retention.erasure.restore_mode' => true, 'retention.erasure.restore_epoch' => (string) Str::uuid7()]);
        $this->blocked(fn () => app(ErasureReconciliationService::class)->run($org->id, $this->audit($org), str_repeat('0', 64), $org->id), 'ERASURE_RESTORE_BLOCKED');
        $this->assertDatabaseCount('tenant_erasure_operations', 1);
    }

    public function test_a_hold_missing_from_a_source_backup_is_imported_even_if_it_was_later_released(): void
    {
        [$org] = $this->scenario();
        $control = app(RetentionControlService::class);
        DB::beginTransaction();
        $control->run($org->id, $this->audit($org), 'hold', 0, 'case-preserve', $org->id);
        $control->run($org->id, $this->audit($org), 'release-hold', 1, 'case-preserve', $org->id);
        DB::rollBack();
        $this->assertSame([], app(RetentionControlRepository::class)->state($org->id)['holds']);
        $this->blocked(fn () => app(ErasureAccessGate::class)->assertReadable($org->id), 'ERASURE_RESTORE_BLOCKED');
        config(['retention.erasure.restore_mode' => true, 'retention.erasure.restore_epoch' => (string) Str::uuid7()]);
        $head = app(ErasureJournal::class)->head($org->id);
        $result = app(ErasureReconciliationService::class)->run($org->id, $this->audit($org), $head, $org->id);
        $this->assertSame(1, $result->data['preservation_holds_imported']);
        $this->assertSame(['case-preserve'], app(RetentionControlRepository::class)->state($org->id)['holds']);
        $this->assertSame([], app(ErasureAccessGate::class)->missingPreservations($org->id));
        $state = app(RetentionControlRepository::class)->state($org->id);
        $control->run($org->id, $this->audit($org), 'release-hold', $state['version'], 'case-preserve', $org->id);
        $result = app(ErasureReconciliationService::class)->run($org->id, $this->audit($org), $head, $org->id);
        $this->assertTrue($result->data['reconciled']);
    }

    /** @return array{Organization, Person} */
    private function scenario(string $status = 'suspended'): array
    {
        $org = Organization::factory()->withMfaOwner()->create(['status' => $status]);
        $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'former', 'deleted_at' => now()]);
        PersonDeparture::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        config(['retention.control_operators' => [$org->owner_user_id => [$org->id]], 'retention.erasure.operators' => [$org->owner_user_id => [$org->id]]]);
        app(ManualErasureService::class)->initializeJournal($org->id, $this->audit($org));

        return [$org, $person];
    }

    private function audit(Organization $org): OrganizationAuditData
    {
        return new OrganizationAuditData($org->owner_user_id, 'Restore fixture decision', (string) Str::uuid7());
    }

    private function approveAndComplete(Organization $org, string $scope, string $target): void
    {
        $service = app(ManualErasureService::class);
        $plan = $service->plan($org->id, $this->audit($org), $scope, $target)->data['plan'];
        $operation = $service->begin($org->id, $this->audit($org), $scope, $target, $plan['source_digest'], 'restore-fixture', $target)->data['operation'];
        $this->complete($org, $operation['id']);
    }

    private function complete(Organization $org, string $id): void
    {
        $service = app(ManualErasureService::class);
        for ($batch = 0; $batch < 150; $batch++) {
            $operation = $service->resume($org->id, $this->audit($org), $id)->data['operation'];
            if ($operation['status'] !== 'erasing') {
                foreach (RetentionCatalog::UNVERIFIED_COPIES as $category) {
                    $service->acknowledge($org->id, $this->audit($org), $id, $category, 'restore-reviewed-'.$category);
                }

                return;
            }
        }
        $this->fail('The fixture must finish within its bound.');
    }

    private function blocked(\Closure $action, string $code): void
    {
        try {
            $action();
            $this->fail('Expected '.$code);
        } catch (DomainConflictException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }
}
