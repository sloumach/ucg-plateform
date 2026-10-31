<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\ArtifactStorageException;
use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\ErasureAccessGate;
use App\Modules\Tenancy\Application\Services\ManualErasureService;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Domain\Contracts\ArtifactLedger;
use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Modules\Tenancy\Infrastructure\Persistence\ScopedErasurePermit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ManualErasureTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['database.connections.erasure_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
            'retention.erasure.journal_connection' => 'erasure_test', 'retention.erasure.journal_independence_reviewed' => true,
            'retention.erasure.installation_id' => (string) Str::uuid7(), 'retention.erasure.journal_key' => str_repeat('independent-secret-', 5),
            'retention.erasure.enabled' => true, 'retention.control_enabled' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('erasure_test');
        parent::tearDown();
    }

    public function test_person_erasure_is_explicit_scoped_and_retains_global_accounts_and_evidence(): void
    {
        [$org, $person] = $this->scenario();
        $other = Person::factory()->create();
        $service = app(ManualErasureService::class);
        $plan = $service->plan($org->id, $this->audit($org), 'person', $person->id)->data['plan'];
        $this->assertSame([], $plan['blockers']);
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $operation = $this->begin($org, $person->id);
        $this->assertSame('erasing', $operation['status']);
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertCount(1, app(ErasureJournal::class)->intents($org->id, 0, 1000));
        $operation = $this->drain($org, $operation['id']);
        $this->assertSame('awaiting_copies', $operation['status']);
        $this->assertDatabaseMissing('people', ['id' => $person->id]);
        $this->assertDatabaseMissing('person_departures', ['person_id' => $person->id]);
        $this->assertModelExists($other);
        $this->assertDatabaseHas('users', ['id' => $org->owner_user_id]);
        foreach (RetentionCatalog::UNVERIFIED_COPIES as $category) {
            $result = $service->acknowledge($org->id, $this->audit($org), $operation['id'], $category, 'review-'.$category);
        }
        $this->assertSame('completed', $result->data['operation']['status']);
        $this->assertFalse($result->data['production_qualified']);
        $this->assertSame('operator_attestations', $result->data['copy_evidence']);
        $this->assertNull(app(RetentionControlRepository::class)->state($org->id)['quarantine']);
        $this->assertGreaterThan(3, DB::table('tenant_erasure_events')->count());
    }

    public function test_shared_contract_blocks_person_erasure_before_journal_or_quarantine(): void
    {
        [$org, $person] = $this->scenario();
        ContractRecord::factory()->create(['organization_id' => $org->id, 'responsible_person_id' => $person->id]);
        $plan = app(ManualErasureService::class)->plan($org->id, $this->audit($org), 'person', $person->id)->data['plan'];
        $this->assertContains('contracts:shared_person_reference', $plan['blockers']);
        $this->conflict(fn () => $this->begin($org, $person->id), 'ERASURE_PLAN_CHANGED');
        $this->assertDatabaseCount('tenant_erasure_operations', 0);
        $this->assertSame([], app(ErasureJournal::class)->intents($org->id, 0, 1000));
        $this->assertDatabaseHas('people', ['id' => $person->id]);
    }

    public function test_disabled_execution_stale_digest_and_missing_journal_are_fail_closed(): void
    {
        [$org, $person] = $this->scenario();
        config(['retention.erasure.enabled' => false]);
        $this->conflict(fn () => $this->begin($org, $person->id), 'ERASURE_DISABLED');
        config(['retention.erasure.enabled' => true]);
        $this->conflict(fn () => $this->begin($org, $person->id, str_repeat('0', 64)), 'ERASURE_PLAN_CHANGED');
        config(['retention.erasure.journal_connection' => config('database.default')]);
        $this->conflict(fn () => $this->begin($org, $person->id), 'ERASURE_JOURNAL_UNAVAILABLE');
        $this->assertDatabaseCount('tenant_erasure_operations', 0);
        $this->assertDatabaseHas('people', ['id' => $person->id]);
    }

    public function test_erasure_scope_is_separate_from_retention_scope(): void
    {
        [$org, $person] = $this->scenario();
        config(['retention.erasure.operators' => []]);
        $this->expectException(AuthorizationException::class);
        app(ManualErasureService::class)->plan($org->id, $this->audit($org), 'person', $person->id);
    }

    public function test_hold_after_approval_suspends_erasure_without_removing_rows(): void
    {
        [$org, $person] = $this->scenario();
        $operation = $this->begin($org, $person->id);
        $state = app(RetentionControlRepository::class)->state($org->id);
        app(RetentionControlService::class)->run($org->id, $this->audit($org), 'hold', $state['version'], 'case-preserve', $org->id);
        $this->conflict(fn () => app(ManualErasureService::class)->resume($org->id, $this->audit($org), $operation['id']), 'ERASURE_BLOCKED');
        $this->assertDatabaseHas('people', ['id' => $person->id]);
    }

    public function test_org_erasure_removes_business_histories_and_files_but_keeps_tombstone_and_global_owner(): void
    {
        [$org, $person] = $this->scenario('archived');
        $operation = $this->begin($org, $org->id, null, 'organization');
        $operation = $this->drain($org, $operation['id']);
        $this->assertSame('awaiting_copies', $operation['status']);
        $this->assertDatabaseMissing('people', ['id' => $person->id]);
        $this->assertDatabaseMissing('organization_settings', ['organization_id' => $org->id]);
        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'name' => '[Erased organization]', 'slug' => 'erased-'.$org->id]);
        $this->assertDatabaseHas('users', ['id' => $org->owner_user_id]);
        foreach (RetentionCatalog::UNVERIFIED_COPIES as $category) {
            app(ManualErasureService::class)->acknowledge($org->id, $this->audit($org), $operation['id'], $category, 'verified-'.$category);
        }
        $this->assertSame('erasure:'.$operation['id'], app(RetentionControlRepository::class)->state($org->id)['quarantine']);
    }

    public function test_ordinary_sql_guards_remain_active_and_damaged_guards_block_erasure(): void
    {
        [$org, $person] = $this->scenario();
        try {
            DB::transaction(fn () => DB::table('people')->where('id', $person->id)->delete());
            $this->fail('An ordinary delete must still be denied.');
        } catch (QueryException) {
            $this->assertDatabaseHas('people', ['id' => $person->id]);
        }
        $operation = $this->begin($org, $person->id);
        DB::table('retention_guard_definitions')->where('id', DB::table('retention_guard_definitions')->value('id'))->update(['wrapped_hash' => str_repeat('0', 64)]);
        $this->conflict(fn () => app(ManualErasureService::class)->resume($org->id, $this->audit($org), $operation['id']), 'ERASURE_GUARDS_CHANGED');
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertDatabaseCount('tenant_erasure_permits', 0);
    }

    public function test_console_requires_valid_approval_and_never_writes_by_default(): void
    {
        [$org, $person] = $this->scenario();
        $this->assertSame(0, Artisan::call('organizations:erase', ['organization' => $org->id, '--scope' => 'person', '--target' => $person->id,
            '--actor' => $org->owner_user_id, '--reason' => 'Read fixture scope']));
        $this->assertSame(1, Artisan::call('organizations:erase', ['organization' => $org->id, '--action' => 'begin', '--scope' => 'person', '--target' => $person->id,
            '--actor' => $org->owner_user_id, '--reason' => 'Missing approval']));
        $this->assertDatabaseCount('tenant_erasure_operations', 0);
    }

    public function test_a_failed_file_removal_preserves_metadata_accounting_and_resumable_progress(): void
    {
        [$org] = $this->scenario('archived');
        $artifact = app(ArtifactLedger::class)->reserve($org->id, 'Private fixture.txt', 7, 'local', 1000);
        $operation = $this->begin($org, $org->id, null, 'organization');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->with('tenants/'.$org->id.'/artifacts/'.$artifact['id'])->andReturnTrue();
        $disk->shouldReceive('delete')->once()->with('tenants/'.$org->id.'/artifacts/'.$artifact['id'])->andReturnFalse();
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('local')->andReturn($disk);
        app()->instance(FilesystemFactory::class, $factory);
        try {
            app(ManualErasureService::class)->resume($org->id, $this->audit($org), $operation['id']);
            $this->fail('A failed removal cannot advance progress or discard metadata.');
        } catch (ArtifactStorageException) {
            $this->assertDatabaseHas('tenant_artifacts', ['id' => $artifact['id'], 'bytes' => 7]);
            $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $org->id, 'bytes' => 7]);
            $stored = app(ManualErasureService::class)->inspect($org->id, $this->audit($org), $operation['id'])->data['operation'];
            $this->assertSame(0, $stored['progress']['files_cursor']);
            $this->assertSame('erasing', $stored['status']);
        }
    }

    public function test_a_person_permit_cannot_remove_another_person_or_modify_identity_fields(): void
    {
        [$org, $person] = $this->scenario();
        $other = Person::factory()->create(['organization_id' => $org->id]);
        $operation = $this->begin($org, $person->id);
        foreach ([[$other->id, null], [$person->id, ['first_name' => 'Unexpected change']]] as [$target, $expected]) {
            try {
                DB::transaction(fn () => app(ScopedErasurePermit::class)->run($org->id, $operation['id'], 'people', 'id', $target, $expected,
                    fn () => DB::table('people')->where('id', $target)->delete()));
                $this->fail('The approved target and allowed metadata fields must be enforced.');
            } catch (\LogicException) {
                $this->assertDatabaseHas('people', ['id' => $target]);
            }
        }
        $this->assertDatabaseCount('tenant_erasure_permits', 0);
    }

    public function test_postgresql_journal_is_physically_separate_durable_and_append_only(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A dedicated PostgreSQL database is required for this journal driver.');
        }
        [$org, $person] = $this->scenario();
        $settings = config('database.connections.'.DB::getDefaultConnection());
        config(['database.connections.erasure_pg_admin' => $settings]);
        $admin = DB::connection('erasure_pg_admin')->getPdo();
        $name = 'ucg_erasure_journal_'.bin2hex(random_bytes(6));
        try {
            $admin->exec('CREATE DATABASE "'.$name.'"');
            config(['database.connections.erasure_pg' => [...$settings, 'url' => null, 'database' => $name],
                'retention.erasure.journal_connection' => 'erasure_pg']);
            app(ManualErasureService::class)->initializeJournal($org->id, $this->audit($org));
            $operation = $this->begin($org, $person->id);
            $this->assertCount(1, app(ErasureJournal::class)->intents($org->id, 0, 1000));
            $state = app(RetentionControlRepository::class)->state($org->id);
            app(RetentionControlService::class)->run($org->id, $this->audit($org), 'hold', $state['version'], 'journal-preserve', $org->id);
            $this->assertCount(1, app(ErasureJournal::class)->preservations($org->id));
            foreach (['retention_erasure_journal', 'retention_preservation_journal'] as $table) {
                try {
                    DB::connection('erasure_pg')->transaction(fn () => DB::connection('erasure_pg')->table($table)->delete());
                    $this->fail('An independent journal must reject removal.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0] ?? null);
                }
            }
            $this->assertDatabaseHas('tenant_erasure_operations', ['id' => $operation['id']]);
            config(['retention.erasure.journal_connection' => 'erasure_pg_admin']);
            $this->conflict(fn () => app(ErasureJournal::class)->assertReady(), 'ERASURE_JOURNAL_UNAVAILABLE');
        } finally {
            DB::purge('erasure_pg');
            $admin->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
            DB::purge('erasure_pg_admin');
        }
    }

    #[Group('tenant-isolation')]
    public function test_shared_operator_cannot_approve_a_foreign_person_under_a_local_organization(): void
    {
        [$alpha, $person, $beta, $foreign] = $this->twoOrganizations();
        $before = (array) DB::table('people')->where('id', $foreign->id)->firstOrFail();
        $service = app(ManualErasureService::class);
        $plan = $service->plan($alpha->id, $this->audit($alpha), 'person', $foreign->id)->data['plan'];
        $this->assertContains('person_not_soft_deleted', $plan['blockers']);
        $this->conflict(fn () => $service->begin($alpha->id, $this->audit($alpha), 'person', $foreign->id,
            $plan['source_digest'], 'foreign-person-refused', $foreign->id), 'ERASURE_PLAN_CHANGED');
        $this->assertSame($before, (array) DB::table('people')->where('id', $foreign->id)->firstOrFail());
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertDatabaseCount('tenant_erasure_operations', 0);
        $this->assertSame([], app(ErasureJournal::class)->intents($alpha->id, 0, 1000));
        $this->assertSame([], app(ErasureJournal::class)->intents($beta->id, 0, 1000));
        $this->assertNull(app(RetentionControlRepository::class)->state($alpha->id)['quarantine']);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_operation_cannot_be_inspected_resumed_or_acknowledged_even_by_a_shared_operator(): void
    {
        [$alpha, $person, $beta, $foreign] = $this->twoOrganizations();
        $operation = $this->begin($beta, $foreign->id);
        $before = DB::table('tenant_erasure_operations')->where('id', $operation['id'])->first();
        $events = DB::table('tenant_erasure_events')->count();
        $service = app(ManualErasureService::class);
        foreach ([fn () => $service->inspect($alpha->id, $this->audit($alpha), $operation['id']),
            fn () => $service->resume($alpha->id, $this->audit($alpha), $operation['id'])] as $action) {
            try {
                $action();
                $this->fail('An authorized operator must still select the operation organization.');
            } catch (RecordNotFoundException) {
                $this->assertDatabaseHas('people', ['id' => $foreign->id]);
            }
        }
        $this->conflict(fn () => $service->acknowledge($alpha->id, $this->audit($alpha), $operation['id'], 'exports', 'foreign-receipt'), 'ERASURE_BLOCKED');
        $this->assertSame((array) $before, (array) DB::table('tenant_erasure_operations')->where('id', $operation['id'])->first());
        $this->assertSame($events, DB::table('tenant_erasure_events')->count());
        $this->assertDatabaseCount('tenant_erasure_permits', 0);
        $this->assertSame('awaiting_copies', $this->drain($beta, $operation['id'])['status']);
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertDatabaseMissing('people', ['id' => $foreign->id]);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_signed_intent_cannot_be_replayed_or_relabelled_for_another_organization(): void
    {
        [$alpha, $person, $beta, $foreign] = $this->twoOrganizations();
        $operation = $this->begin($beta, $foreign->id);
        $journal = app(ErasureJournal::class);
        $intent = $journal->intents($beta->id, 0, 1000)[0];
        $epoch = (string) Str::uuid7();
        config(['retention.erasure.restore_mode' => true, 'retention.erasure.restore_epoch' => $epoch]);
        foreach ([$intent, [...$intent, 'organization_id' => $alpha->id]] as $untrusted) {
            $this->conflict(fn () => app(ManualErasureService::class)->replayIntent($alpha->id, $this->audit($alpha), $untrusted,
                $epoch, $journal->head($alpha->id)), 'ERASURE_JOURNAL_CONFLICT');
        }
        $this->assertDatabaseCount('tenant_erasure_operations', 1);
        $this->assertSame($operation, app(ManualErasureService::class)->inspect($beta->id, $this->audit($beta), $operation['id'])->data['operation']);
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertDatabaseHas('people', ['id' => $foreign->id]);
        $this->assertNull(app(RetentionControlRepository::class)->state($alpha->id)['quarantine']);
        $this->assertSame([], $journal->intents($alpha->id, 0, 1000));
    }

    #[Group('tenant-isolation')]
    public function test_completing_alpha_does_not_release_beta_or_block_alpha_on_beta_pending_intent(): void
    {
        [$alpha, $person, $beta, $foreign] = $this->twoOrganizations();
        $first = $this->begin($alpha, $person->id);
        $second = $this->begin($beta, $foreign->id);
        $this->drain($alpha, $first['id']);
        $service = app(ManualErasureService::class);
        foreach (RetentionCatalog::UNVERIFIED_COPIES as $category) {
            $result = $service->acknowledge($alpha->id, $this->audit($alpha), $first['id'], $category, 'alpha-review-'.$category);
        }
        $this->assertSame('completed', $result->data['operation']['status']);
        app(ErasureAccessGate::class)->assertReadable($alpha->id);
        $this->conflict(fn () => app(ErasureAccessGate::class)->assertReadable($beta->id), 'ERASURE_RESTORE_BLOCKED');
        $this->assertNull(app(RetentionControlRepository::class)->state($alpha->id)['quarantine']);
        $this->assertSame('erasure:'.$second['id'], app(RetentionControlRepository::class)->state($beta->id)['quarantine']);
        $this->assertDatabaseMissing('people', ['id' => $person->id]);
        $this->assertDatabaseHas('people', ['id' => $foreign->id]);
        $this->assertDatabaseHas('users', ['id' => $alpha->owner_user_id]);
    }

    /** @return array{Organization, Person, Organization, Person} */
    private function twoOrganizations(): array
    {
        [$alpha, $person] = $this->scenario();
        $beta = Organization::factory()->create(['owner_user_id' => $alpha->owner_user_id, 'status' => 'suspended']);
        $foreign = Person::factory()->create(['organization_id' => $beta->id, 'status' => 'former', 'deleted_at' => now()]);
        PersonDeparture::factory()->create(['organization_id' => $beta->id, 'person_id' => $foreign->id]);
        config(['retention.control_operators' => [$alpha->owner_user_id => [$alpha->id, $beta->id]],
            'retention.erasure.operators' => [$alpha->owner_user_id => [$alpha->id, $beta->id]]]);
        app(ManualErasureService::class)->initializeJournal($beta->id, $this->audit($beta));

        return [$alpha, $person, $beta, $foreign];
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
        return new OrganizationAuditData($org->owner_user_id, 'Explicit fixture erasure review', (string) Str::uuid7());
    }

    /** @return array<string, mixed> */
    private function begin(Organization $org, string $target, ?string $digest = null, string $scope = 'person'): array
    {
        $service = app(ManualErasureService::class);
        $digest ??= $service->plan($org->id, $this->audit($org), $scope, $target)->data['plan']['source_digest'];

        return $service->begin($org->id, $this->audit($org), $scope, $target, $digest, 'request-fixture', $target)->data['operation'];
    }

    /** @return array<string, mixed> */
    private function drain(Organization $org, string $id): array
    {
        for ($batch = 0; $batch < 150; $batch++) {
            $operation = app(ManualErasureService::class)->resume($org->id, $this->audit($org), $id)->data['operation'];
            if ($operation['status'] !== 'erasing') {
                return $operation;
            }
        }
        $this->fail('The bounded fixture must finish.');
    }

    private function conflict(\Closure $action, string $code): void
    {
        try {
            $action();
            $this->fail('Expected '.$code);
        } catch (DomainConflictException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }
}
