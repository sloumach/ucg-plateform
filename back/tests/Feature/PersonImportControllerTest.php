<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Services\PersonImportProcessor;
use App\Modules\People\Application\Validation\PersonImportInput;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Infrastructure\Queue\ProcessPersonImport;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonImportControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_failed_metadata_transaction_removes_only_the_new_reserved_artifact(): void
    {
        [$org] = $this->editor();
        PersonImport::created(fn () => throw new \RuntimeException('Injected metadata failure'));
        try {
            $this->post($this->path($org), ['delimiter' => ',', 'file' => UploadedFile::fake()->createWithContent('people.csv', "pseudo\nAlex")], ['Accept' => 'application/json'])->assertServerError();
        } finally {
            Event::forget('eloquent.created: '.PersonImport::class);
        }
        $this->assertDatabaseCount('person_imports', 0);
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('tenants/'.$org->id));
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $org->id, 'bytes' => 0]);
    }

    public function test_import_lists_are_bounded_and_have_no_per_run_queries(): void
    {
        [$org, $user] = $this->editor();
        PersonImport::factory()->create(['organization_id' => $org->id, 'actor_user_id' => $user->id]);
        $this->getJson($this->path($org))->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->path($org))->assertOk()->assertJsonCount(1, 'data');
        $one = count(DB::getQueryLog());
        PersonImport::factory()->count(20)->create(['organization_id' => $org->id, 'actor_user_id' => $user->id]);
        DB::flushQueryLog();
        $this->getJson($this->path($org))->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 21);
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->getJson($this->path($org).'?page=0')->assertUnprocessable();
    }

    public function test_upload_and_preview_do_not_create_people_and_private_data_is_encrypted(): void
    {
        [$org,$user] = $this->editor();
        $csv = "pseudo,email\nAlex,SECRET@example.test\n alex ,OTHER@example.test\nBob,invalid";
        $id = $this->upload($org, $csv);
        $this->assertDatabaseCount('people', 0);
        Queue::assertNothingPushed();
        $raw = DB::table('person_imports')->where('id', $id)->firstOrFail();
        $artifact = DB::table('tenant_artifacts')->where('id', $raw->artifact_id)->firstOrFail();
        $contents = Storage::disk('local')->get('tenants/'.$org->id.'/artifacts/'.$artifact->id);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('SECRET@example.test', $contents);
        $this->assertStringNotContainsString('pseudo', $raw->headers);
        $version = PersonImport::findOrFail($id)->version;
        $preview = $this->postJson($this->path($org).'/'.$id.'/preview', $this->mapping($version, ['pseudonym' => 0, 'contact_email' => 1]))
            ->assertOk()->assertJsonPath('data.counts.ready', 1)->assertJsonPath('data.counts.duplicate', 1)->assertJsonPath('data.counts.invalid', 1);
        $this->assertDatabaseCount('people', 0);
        $this->assertStringNotContainsString('SECRET@example.test', DB::table('person_import_rows')->where('import_id', $id)->value('values'));
        $this->assertStringNotContainsString('SECRET@example.test', json_encode(DB::table('organization_audit_events')->get()->all(), JSON_THROW_ON_ERROR));
        $this->postJson($this->path($org).'/'.$id.'/confirm', ['version' => $preview->json('data.version'), 'confirm' => true])->assertConflict();
        Queue::assertNothingPushed();
        $this->postJson($this->path($org).'/'.$id.'/confirm', ['version' => $preview->json('data.version'), 'confirm' => true, 'acknowledge_rejected' => true])
            ->assertOk()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(ProcessPersonImport::class, fn ($job) => $job->importId === $id && $job->context->tenantId === $org->id && $job->context->actorUserId === $user->id);
        $this->assertDatabaseCount('people', 0);
    }

    public function test_identical_file_upload_is_idempotent_and_does_not_reserve_storage_twice(): void
    {
        [$org] = $this->editor();
        $id = $this->upload($org, "pseudo\nAlex");
        $this->assertSame($id, $this->upload($org, "pseudo\nAlex"));
        $this->assertDatabaseCount('person_imports', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
    }

    public function test_an_existing_former_person_is_a_duplicate_not_reactivated_or_overwritten(): void
    {
        [$org] = $this->editor();
        $person = Person::factory()->create(['organization_id' => $org->id, 'pseudonym' => 'Alex', 'status' => 'former']);
        $id = $this->upload($org, "pseudo\nalex");
        $this->postJson($this->path($org).'/'.$id.'/preview', $this->mapping(PersonImport::findOrFail($id)->version))
            ->assertOk()->assertJsonPath('data.rows.0.errors.0', 'EXISTING_PERSON');
        $this->assertSame('former', $person->refresh()->status->value);
        $this->assertDatabaseCount('people', 1);
    }

    #[Group('tenant-isolation')]
    public function test_cross_tenant_uuid_and_report_are_not_disclosed_to_multi_tenant_actor(): void
    {
        [$org,$user] = $this->editor();
        $id = $this->upload($org, "pseudo\nSECRET_A");
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, PersonImportInput::PERMISSIONS);
        foreach (['', '/report'] as $suffix) {
            $this->getJson($this->path($other).'/'.$id.$suffix)->assertNotFound();
        }
        $this->postJson($this->path($other).'/'.$id.'/preview', $this->mapping(PersonImport::findOrFail($id)->version))->assertNotFound();
        $this->postJson($this->path($other).'/'.$id.'/confirm', ['version' => PersonImport::findOrFail($id)->version, 'confirm' => true])->assertNotFound();
        $this->getJson($this->path($other))->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('person_imports', ['id' => $id, 'organization_id' => $org->id, 'status' => 'uploaded']);
        $this->assertDatabaseCount('person_import_rows', 0);
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        Queue::assertNothingPushed();
    }

    #[Group('tenant-isolation')]
    public function test_serialized_import_and_replay_keep_the_dispatched_tenant_after_http_switch(): void
    {
        [$org, $user] = $this->editor();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, PersonImportInput::PERMISSIONS);
        $beta = Person::factory()->create(['organization_id' => $other->id, 'pseudonym' => 'Alex']);
        $this->selectOrganization($org);
        $run = $this->previewImport($org, "pseudo\nAlex\nBob");
        $this->postJson($this->path($org).'/'.$run->id.'/confirm', ['version' => $run->version, 'confirm' => true])->assertOk();
        $transport = $this->serializedQueuedImport();
        $this->selectOrganization($other);
        $files = $this->artifactFiles();

        Queue::fake()->except([ProcessPersonImport::class]);
        Bus::dispatchSync(unserialize($transport));
        $auditCount = DB::table('organization_audit_events')->count();
        Bus::dispatchSync(unserialize($transport));

        $this->assertDatabaseHas('person_imports', ['id' => $run->id, 'organization_id' => $org->id, 'status' => 'completed', 'execution_actor_id' => $user->id]);
        $this->assertDatabaseHas('people', ['organization_id' => $org->id, 'pseudonym' => 'Alex', 'user_id' => null]);
        $this->assertDatabaseHas('people', ['organization_id' => $org->id, 'pseudonym' => 'Bob', 'user_id' => null]);
        $this->assertDatabaseHas('people', ['id' => $beta->id, 'organization_id' => $other->id, 'version' => $beta->version]);
        $this->assertDatabaseCount('people', 3);
        $this->assertSame(2, DB::table('person_import_rows')->where('import_id', $run->id)->where('status', 'created')->count());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertSame($files, $this->artifactFiles());
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->assertSame($other->id, session()->get(ActiveOrganizationSession::KEY.'.organization_id'));
        $this->getJson($this->path($other))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($other).'/'.$run->id)->assertNotFound();
        $this->getJson($this->path($other).'/'.$run->id.'/report')->assertNotFound();
        $this->selectOrganization($org);
        $this->getJson($this->path($org).'/'.$run->id)->assertOk()->assertJsonPath('data.counts.created', 2);
        $this->get($this->path($org).'/'.$run->id.'/report')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', false])]
    #[TestWith(['role', false])]
    #[TestWith(['permission', false])]
    #[TestWith(['membership', true])]
    #[TestWith(['role', true])]
    #[TestWith(['permission', true])]
    public function test_beta_rights_cannot_rescue_a_serialized_alpha_import_after_revocation(string $revocation, bool $afterChunk): void
    {
        [$org, $user] = $this->editor();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, PersonImportInput::PERMISSIONS);
        $this->selectOrganization($org);
        $run = $this->previewImport($org, "pseudo\nAlex\nBob");
        $this->postJson($this->path($org).'/'.$run->id.'/confirm', ['version' => $run->version, 'confirm' => true])->assertOk();
        $transport = $this->serializedQueuedImport();
        if ($afterChunk) {
            config(['person_imports.chunk_size' => 1]);
            $this->assertTrue(app(PersonImportProcessor::class)->process($this->personContext($org, $user), $run->id));
        }
        $people = DB::table('people')->orderBy('id')->get()->map(fn (object $person): array => (array) $person)->all();
        $rows = DB::table('person_import_rows')->where('import_id', $run->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $auditCount = DB::table('organization_audit_events')->count();
        $files = $this->artifactFiles();
        $this->selectOrganization($other);
        $this->revokeImportAccess($org, $user, $revocation);

        Queue::fake()->except([ProcessPersonImport::class]);
        Bus::dispatchSync(unserialize($transport));
        Bus::dispatchSync(unserialize($transport));

        $this->assertDatabaseHas('person_imports', ['id' => $run->id, 'organization_id' => $org->id, 'status' => 'failed', 'failure_code' => 'PROCESSING_INTERRUPTED']);
        $this->assertSame($people, DB::table('people')->orderBy('id')->get()->map(fn (object $person): array => (array) $person)->all());
        $this->assertSame($rows, DB::table('person_import_rows')->where('import_id', $run->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame($afterChunk ? 1 : 0, DB::table('person_import_rows')->where('import_id', $run->id)->where('status', 'created')->count());
        $this->assertSame($afterChunk ? 1 : 2, DB::table('person_import_rows')->where('import_id', $run->id)->where('status', 'ready')->count());
        $this->assertSame(0, DB::table('people')->where('organization_id', $other->id)->count());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertSame($files, $this->artifactFiles());
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->assertSame($other->id, session()->get(ActiveOrganizationSession::KEY.'.organization_id'));
        $this->getJson($this->path($other))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($org).'/'.$run->id)->assertStatus($revocation === 'membership' ? 404 : 409)->assertJsonMissingPath('data');
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    #[TestWith(['permission', 403])]
    public function test_revocation_hides_warmed_imports_and_reports_and_prevents_confirmation(string $revocation, int $status): void
    {
        [$org, $user] = $this->editor();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, PersonImportInput::PERMISSIONS);
        $run = $this->previewImport($org, "pseudo\nPRIVATE_ALPHA");
        $this->getJson($this->path($org).'/'.$run->id)->assertOk();
        $this->get($this->path($org).'/'.$run->id.'/report')->assertOk();
        $files = $this->artifactFiles();
        $auditCount = DB::table('organization_audit_events')->count();
        $this->revokeImportAccess($org, $user, $revocation);

        foreach (['', '/'.$run->id, '/'.$run->id.'/report'] as $suffix) {
            $this->getJson($this->path($org).$suffix)->assertStatus($status)->assertJsonMissingPath('data');
        }
        $this->postJson($this->path($org).'/'.$run->id.'/preview', $this->mapping($run->version))->assertStatus($status);
        $this->postJson($this->path($org).'/'.$run->id.'/confirm', ['version' => $run->version, 'confirm' => true])->assertStatus($status);
        $this->post($this->path($org), ['delimiter' => ',', 'file' => UploadedFile::fake()->createWithContent('new.csv', "pseudo\nNew")], ['Accept' => 'application/json'])->assertStatus($status);
        $this->getJson($this->path($other))->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('person_imports', ['id' => $run->id, 'status' => 'previewed', 'version' => $run->version]);
        $this->assertDatabaseHas('person_import_rows', ['import_id' => $run->id, 'status' => 'ready', 'person_id' => null]);
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('person_imports', 1);
        $this->assertDatabaseCount('person_import_rows', 1);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertSame($files, $this->artifactFiles());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        Queue::assertNothingPushed();
    }

    private function selectOrganization(Organization $organization): void
    {
        $revision = $this->postJson('/api/v1/active-organization', ['organization_id' => $organization->id])->assertOk()->json('data.revision');
        $this->withHeader('X-Tenant-Revision', $revision);
        $this->postJson('/api/v1/active-organization/confirmation', ['organization_id' => $organization->id, 'revision' => $revision, 'confirm' => true])->assertOk();
    }

    #[Group('tenant-isolation')]
    public function test_identical_csv_files_have_separate_imports_and_private_artifacts_in_each_tenant(): void
    {
        [$org, $user] = $this->editor();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, PersonImportInput::PERMISSIONS);
        $csv = "pseudo\nPRIVATE_IDENTICAL";
        $alpha = $this->previewImport($org, $csv);
        $beta = $this->previewImport($other, $csv);
        $this->assertNotSame($alpha->id, $beta->id);
        $this->assertNotSame($alpha->fingerprint, $beta->fingerprint);
        $this->assertNotSame($alpha->artifact_id, $beta->artifact_id);

        $artifacts = app(TenantArtifacts::class);
        foreach ([[$org, $alpha, $beta], [$other, $beta, $alpha]] as [$organization, $own, $foreign]) {
            $context = $this->personContext($organization, $user);
            $this->assertSame($csv, decrypt($artifacts->read($context, $own->artifact_id), false));
            try {
                $artifacts->read($context, $foreign->artifact_id);
                $this->fail('An import artifact must not be readable from another tenant.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseHas('person_imports', ['id' => $foreign->id, 'version' => $foreign->version, 'status' => 'previewed']);
            }
            $this->getJson($this->path($organization))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        }

        $this->assertDatabaseCount('person_imports', 2);
        $this->assertDatabaseCount('person_import_rows', 2);
        $this->assertDatabaseCount('tenant_artifacts', 2);
        $this->assertCount(1, Storage::disk('local')->allFiles('tenants/'.$org->id));
        $this->assertCount(1, Storage::disk('local')->allFiles('tenants/'.$other->id));
        $this->assertDatabaseCount('people', 0);
        Queue::assertNothingPushed();
    }

    private function serializedQueuedImport(): string
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);
        $job = $queue->pushed(ProcessPersonImport::class)->sole();
        $this->assertInstanceOf(ProcessPersonImport::class, $job);

        return serialize($job);
    }

    /** @return array<string, string> */
    private function artifactFiles(): array
    {
        $files = [];
        foreach (Storage::disk('local')->allFiles('/') as $path) {
            $contents = Storage::disk('local')->get($path);
            $this->assertIsString($contents);
            $files[$path] = hash('sha256', $contents);
        }
        ksort($files);

        return $files;
    }

    private function previewImport(Organization $organization, string $csv): PersonImport
    {
        $id = $this->upload($organization, $csv);
        $this->postJson($this->path($organization).'/'.$id.'/preview', $this->mapping(PersonImport::findOrFail($id)->version))->assertOk();

        return PersonImport::findOrFail($id);
    }

    private function revokeImportAccess(Organization $organization, User $user, string $revocation): void
    {
        if ($revocation === 'membership') {
            OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)
                ->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } else {
            $assignment = OrganizationRoleAssignment::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
            if ($revocation === 'role') {
                $assignment->forceFill(['status' => 'revoked'])->save();
            } else {
                $assignment->role->forceFill(['permissions' => ['sports.read', 'sports.write', 'administrative.read']])->save();
            }
        }
    }

    public function test_read_permissions_without_writes_do_not_expose_file_headers_or_rows(): void
    {
        [$org] = $this->editor(['sports.read', 'administrative.read']);
        $this->getJson($this->path($org))->assertForbidden();
        $this->post($this->path($org), ['delimiter' => ',', 'file' => UploadedFile::fake()->createWithContent('people.csv', "pseudo\nA")], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseCount('person_imports', 0);
    }

    public function test_missing_session_mfa_and_tenant_confirmation_are_enforced(): void
    {
        [$org] = $this->editor();
        $this->withSession([MfaSession::PROOF_KEY => null])->getJson($this->path($org))->assertForbidden();
        [$org,$user] = $this->editor();
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $org->id, 'actor_user_id' => $user->id,
            'revision' => $revision, 'confirmed' => false]])->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->post($this->path($org), ['delimiter' => ',', 'file' => UploadedFile::fake()->createWithContent('people.csv', "pseudo\nA")],
            ['Accept' => 'application/json'])->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('person_imports', 0);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function badMappings(): array
    {
        return [
            'account link' => [['mapping' => ['pseudonym' => 0, 'user_id' => 1]]],
            'duplicate column' => [['mapping' => ['pseudonym' => 0, 'first_name' => 0]]],
            'outside file' => [['mapping' => ['pseudonym' => 12]]],
            'no pseudonym' => [['mapping' => ['contact_email' => 1]]],
            'unknown default' => [['defaults' => ['language' => 'fr', 'timezone' => 'UTC', 'status' => 'active', 'user_id' => 1]]],
            'invalid timezone' => [['defaults' => ['language' => 'fr', 'timezone' => 'INVALID', 'status' => 'active']]],
            'extra tenant' => [['organization_id' => 'bad']],
        ];
    }

    /** @param array<string,mixed> $change */
    #[DataProvider('badMappings')]
    public function test_mapping_validation_preserves_the_previous_preview(array $change): void
    {
        [$org] = $this->editor();
        $id = $this->upload($org, "pseudo,email\nAlex,alex@example.test");
        $run = PersonImport::findOrFail($id);
        $this->postJson($this->path($org).'/'.$id.'/preview', [...$this->mapping($run->version), ...$change])->assertUnprocessable();
        $this->assertDatabaseCount('person_import_rows', 0);
        $this->assertSame($run->version, $run->refresh()->version);
    }

    public function test_stale_versions_and_unpreviewed_confirmation_are_refused(): void
    {
        [$org] = $this->editor();
        $id = $this->upload($org, "pseudo\nAlex");
        $run = PersonImport::findOrFail($id);
        $this->postJson($this->path($org).'/'.$id.'/confirm', ['version' => $run->version, 'confirm' => true])->assertConflict();
        $this->postJson($this->path($org).'/'.$id.'/preview', $this->mapping((string) Str::uuid()))->assertConflict();
        $this->assertDatabaseCount('people', 0);
    }

    public function test_report_contains_only_closed_codes_and_no_spreadsheet_values(): void
    {
        [$org] = $this->editor();
        $id = $this->upload($org, "pseudo,email\n=HYPERLINK(),SECRET@example.test");
        $this->postJson($this->path($org).'/'.$id.'/preview', $this->mapping(PersonImport::findOrFail($id)->version, ['pseudonym' => 0, 'contact_email' => 1]))->assertOk();
        $response = $this->get($this->path($org).'/'.$id.'/report')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('line,status,errors,person_id', $content);
        $this->assertStringNotContainsString('HYPERLINK', $content);
        $this->assertStringNotContainsString('SECRET', $content);
    }

    public function test_wrong_extension_and_tenant_quota_are_refused_without_file_reservation(): void
    {
        [$org] = $this->editor();
        $this->post($this->path($org), ['delimiter' => ',', 'file' => UploadedFile::fake()->createWithContent('bad.php', "pseudo\nA")], ['Accept' => 'application/json'])->assertUnprocessable();
        config(['tenancy.limit_overrides.'.$org->id.'.import_rows' => 1]);
        $this->post($this->path($org), ['delimiter' => ',', 'file' => UploadedFile::fake()->createWithContent('people.csv', "pseudo\nA\nB")], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->assertDatabaseCount('person_imports', 0);
    }

    /** @param list<string> $permissions
     * @return array{Organization,User} */
    private function editor(array $permissions = PersonImportInput::PERMISSIONS): array
    {
        config(['filesystems.artifacts' => 'local']);
        Storage::fake('local');
        Queue::fake([ProcessPersonImport::class]);
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return [$org, $user];
    }

    private function path(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/people-imports';
    }

    private function upload(Organization $org, string $csv): string
    {
        return $this->post($this->path($org), ['delimiter' => ',', 'file' => UploadedFile::fake()->createWithContent('people.csv', $csv)],
            ['Accept' => 'application/json'])->assertOk()->json('data.id');
    }

    /** @param array<string,int> $mapping
     * @return array<string,mixed> */
    private function mapping(string $version, array $mapping = ['pseudonym' => 0]): array
    {
        return ['version' => $version, 'mapping' => $mapping, 'defaults' => ['language' => 'fr', 'timezone' => 'UTC', 'status' => 'active']];
    }
}
