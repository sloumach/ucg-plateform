<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Services\PersonImportProcessor;
use App\Modules\People\Application\Services\PersonImportService;
use App\Modules\People\Application\Validation\PersonImportInput;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Domain\Models\PersonImportRow;
use App\Modules\People\Infrastructure\Queue\ProcessPersonImport;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonImportProcessorTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_worker_refuses_an_account_link_even_if_a_staged_row_was_corrupted(): void
    {
        [$context, $run] = $this->prepared("pseudo,email\nAlex,alex@example.test");
        $row = PersonImportRow::query()->where('import_id', $run->id)->firstOrFail();
        $row->forceFill(['values' => [...$row->values, 'user_id' => $context->actorUserId]])->save();
        try {
            app(PersonImportProcessor::class)->process($context, $run->id);
            $this->fail('An import must never link an account.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('people', 0);
            $this->assertDatabaseHas('person_import_rows', ['id' => $row->id, 'status' => 'ready']);
        }
    }

    public function test_chunks_and_replayed_jobs_create_each_person_only_once_with_no_account_admission(): void
    {
        [$context,$run] = $this->prepared("pseudo,email\nAlex,alex@example.test\nBob,bob@example.test");
        config(['person_imports.chunk_size' => 1]);
        $processor = app(PersonImportProcessor::class);
        $this->assertTrue($processor->process($context, $run->id));
        $this->assertDatabaseCount('people', 1);
        $this->assertFalse($processor->process($context, $run->id));
        $this->assertFalse($processor->process($context, $run->id));
        $this->assertDatabaseCount('people', 2);
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertSame(0, DB::table('people')->whereNotNull('user_id')->count());
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(2, DB::table('person_import_rows')->where('status', 'created')->count());
        $this->assertSame('alex@example.test', Person::query()->where('pseudonym', 'Alex')->firstOrFail()->contact_email);
        $this->assertStringNotContainsString('alex@example.test', DB::table('people')->where('pseudonym', 'Alex')->value('contact_email'));
        $count = DB::table('organization_audit_events')->count();
        app(PersonImportService::class)->confirm($context, $run->id, ['version' => $run->confirmed_version, 'confirm' => true]);
        $this->assertSame($count, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('people', 2);
    }

    public function test_duplicate_appearing_after_preview_is_skipped_without_modifying_existing_person(): void
    {
        [$context,$run] = $this->prepared("pseudo,email\nAlex,alex@example.test");
        $person = Person::factory()->create(['organization_id' => $context->organizationId, 'pseudonym' => 'Alex', 'status' => 'former']);
        $version = $person->version;
        $this->assertFalse(app(PersonImportProcessor::class)->process($context, $run->id));
        $this->assertDatabaseCount('people', 1);
        $this->assertSame($version, $person->refresh()->version);
        $this->assertDatabaseHas('person_import_rows', ['import_id' => $run->id, 'status' => 'skipped']);
    }

    public function test_permission_revoked_after_confirmation_prevents_all_creations(): void
    {
        [$context,$run] = $this->prepared("pseudo,email\nAlex,alex@example.test");
        OrganizationRoleAssignment::query()->where('user_id', $context->actorUserId)->update(['status' => 'revoked']);
        try {
            app(PersonImportProcessor::class)->process($context, $run->id);
            $this->fail('Revoked rights must be checked by the worker.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('people', 0);
            $this->assertDatabaseHas('person_import_rows', ['import_id' => $run->id, 'status' => 'ready']);
        }
    }

    public function test_failure_rolls_back_one_entire_chunk_then_resume_preserves_earlier_commits(): void
    {
        [$context,$run] = $this->prepared("pseudo,email\nAlex,alex@example.test\nBob,bob@example.test\nCharlie,c@example.test");
        config(['person_imports.chunk_size' => 1]);
        $processor = app(PersonImportProcessor::class);
        $this->assertTrue($processor->process($context, $run->id));
        config(['person_imports.chunk_size' => 25]);
        Person::created(function (Person $person): void {
            if ($person->pseudonym === 'Charlie') {
                throw new \RuntimeException('Injected transaction failure');
            }
        });
        try {
            $processor->process($context, $run->id);
            $this->fail('Chunk should roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected transaction failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.Person::class);
        }
        $this->assertDatabaseCount('people', 1);
        $this->assertSame(1, DB::table('person_import_rows')->where('status', 'created')->count());
        $this->assertSame(2, DB::table('person_import_rows')->where('status', 'ready')->count());
        $processor->failed($context->organizationId, $run->id, $context->actorUserId);
        $this->assertSame('failed', $run->refresh()->status);
        app(PersonImportService::class)->confirm($context, $run->id, ['version' => $run->version, 'confirm' => true]);
        $this->assertFalse($processor->process($context, $run->id));
        $this->assertDatabaseCount('people', 3);
        $this->assertSame('completed', $run->refresh()->status);
    }

    public function test_sync_queue_executes_real_tenant_middleware_and_bound_context(): void
    {
        [$context,$run] = $this->prepared("pseudo,email\nAlex,alex@example.test", false);
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseHas('people', ['organization_id' => $context->organizationId, 'pseudonym' => 'Alex']);
        $this->assertDatabaseCount('people', 1);
    }

    public function test_unconfirmed_run_cannot_be_processed_by_direct_service_call(): void
    {
        [$context,$run] = $this->prepared("pseudo,email\nAlex,alex@example.test", true, false);
        $this->assertFalse(app(PersonImportProcessor::class)->process($context, $run->id));
        $this->assertDatabaseCount('people', 0);
    }

    /** @return array{TenantContext,PersonImport} */
    private function prepared(string $csv, bool $fakeQueue = true, bool $confirm = true): array
    {
        config(['filesystems.artifacts' => 'local', 'queue.default' => 'sync']);
        Storage::fake('local');
        if ($fakeQueue) {
            Queue::fake([ProcessPersonImport::class]);
        }
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, PersonImportInput::PERMISSIONS);
        $context = $this->personContext($org, $user);
        $service = app(PersonImportService::class);
        $run = $service->upload($context, $csv, ',');
        $run = $service->preview($context, $run->id, ['version' => $run->version, 'mapping' => ['pseudonym' => 0, 'contact_email' => 1],
            'defaults' => ['language' => 'fr', 'timezone' => 'UTC', 'status' => 'active']]);
        if ($confirm) {
            $run = $service->confirm($context, $run->id, ['version' => $run->version, 'confirm' => true]);
        }

        return [$context, $run];
    }
}
