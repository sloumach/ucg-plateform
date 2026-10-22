<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Domain\Contracts\CompetitionRegistrationRepository;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Reporting\Application\Contracts\ReportExportQueue;
use App\Modules\Reporting\Application\Services\ReportExportProcessor;
use App\Modules\Reporting\Application\Services\ReportExportService;
use App\Modules\Reporting\Infrastructure\Queue\BuildReportExport;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Services\TenantArtifactService;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess, \Tests\Fixtures\ProvidesContractDocuments;

    /** @param list<string> $permissions
     * @return array<string,mixed> */
    private function scenario(array $permissions = ['reporting.export', 'reporting.direction', 'sports.read']): array
    {
        $this->travelTo('2026-10-21 10:00:00 UTC');
        config(['report_exports.processing_enabled' => true, 'queue.default' => 'database',
            'tenancy.cache_store' => 'database', 'filesystems.artifacts' => 'local']);
        Storage::fake('local');
        Queue::fake([BuildReportExport::class]);
        $org = Organization::factory()->create(['timezone' => 'UTC']);
        $user = User::factory()->withMfa()->create();
        $assignment = $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return compact('org', 'user', 'assignment') + ['root' => '/api/v1/tenants/'.$org->id.'/exports',
            'input' => ['card' => 'active_people', 'view' => 'direction', 'from' => '2026-10-21', 'to' => '2026-10-25', 'confirm' => true, 'request_key' => (string) Str::uuid()]];
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function requestExport(array $s): array
    {
        return $this->postJson($s['root'], $s['input'])->assertOk()->json('data');
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $row */
    private function process(array $s, array $row): void
    {
        app(ReportExportProcessor::class)->process($this->personContext($s['org'], $s['user']), $row['id']);
    }

    public function test_request_is_idempotent_reads_never_generate_and_job_transports_only_identifiers(): void
    {
        $s = $this->scenario();
        $this->getJson($s['root'])->assertOk()->assertJsonCount(0, 'data.items')->assertHeader('Cache-Control', 'no-store, private');
        Queue::assertNothingPushed();
        $row = $this->requestExport($s);
        $again = $this->requestExport($s);
        $this->assertSame($row['id'], $again['id']);
        $this->assertDatabaseCount('report_exports', 1);
        $this->assertDatabaseCount('tenant_artifacts', 0);
        Queue::assertPushed(BuildReportExport::class, 1);
        Queue::assertPushed(BuildReportExport::class, fn ($job) => $job->context->tenantId === $s['org']->id && $job->context->actorUserId === $s['user']->id
            && $job->exportId === $row['id'] && $job->queue === 'reports' && $job->afterCommit);
        $this->postJson($s['root'], [...$s['input'], 'to' => '2026-10-26'])->assertConflict()->assertJsonPath('code', 'REPORT_EXPORT_INPUT');
    }

    public function test_worker_exports_only_the_allowed_projection_encrypts_private_storage_and_replay_is_noop(): void
    {
        $s = $this->scenario();
        Person::factory()->count(6)->create(['organization_id' => $s['org']->id, 'status' => 'active', 'pseudonym' => 'Alpha ; "quote"', 'first_name' => 'Hidden identity', 'contact_email' => 'hidden@example.test']);
        Person::factory()->create(['status' => 'active', 'pseudonym' => 'Other tenant']);
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $this->process($s, $row);
        $this->getJson($s['root'].'/'.$row['id'])->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.row_count', 6)
            ->assertJsonMissingPath('data.artifact_id')->assertJsonMissingPath('data.digest');
        $r = $this->get($s['root'].'/'.$row['id'].'/download')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private');
        $csv = $r->getContent();
        $this->assertIsString($csv);
        $this->assertStringContainsString('Alpha ; ""quote""', $csv);
        $this->assertStringContainsString($s['org']->id, $csv);
        $this->assertStringNotContainsString('Hidden identity', $csv);
        $this->assertStringNotContainsString('hidden@example.test', $csv);
        $this->assertStringNotContainsString('Other tenant', $csv);
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $stored = Storage::disk('local')->get(Storage::disk('local')->allFiles()[0]);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('Alpha', $stored);
        $this->assertSame(3, DB::table('organization_audit_events')->where('organization_id', $s['org']->id)->where('action', 'domain.changed')->count());
        $this->assertSame(3, DB::table('organization_sensitive_access_events')->where('organization_id', $s['org']->id)->where('target_id', $row['id'])->count());
    }

    public function test_empty_source_produces_a_complete_header_only_csv(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $this->getJson($s['root'].'/'.$row['id'])->assertOk()->assertJsonPath('data.row_count', 0);
        $csv = $this->get($s['root'].'/'.$row['id'].'/download')->assertOk()->getContent();
        $this->assertIsString($csv);
        $this->assertSame(1, substr_count($csv, "\r\n"));
    }

    #[TestWith(['=HYPERLINK("evil")'])]
    #[TestWith(['  +SUM(1)'])]
    #[TestWith(["\t@evil"])]
    #[TestWith(['-1+2'])]
    public function test_csv_formulas_are_neutralized_even_after_leading_whitespace(string $label): void
    {
        $s = $this->scenario();
        Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'active', 'pseudonym' => $label]);
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $csv = $this->get($s['root'].'/'.$row['id'].'/download')->assertOk()->getContent();
        $this->assertIsString($csv);
        $lines = explode("\r\n", $csv);
        $fields = str_getcsv($lines[1], ';', '"', '');
        $this->assertSame("'".$label, $fields[8]);
    }

    public function test_an_export_is_not_accessible_by_another_actor_or_tenant_even_with_the_same_permissions(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $otherUser = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $otherUser, ['reporting.export', 'reporting.direction', 'sports.read']);
        $this->actingAsWithMfa($otherUser);
        $this->getJson($s['root'])->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson($s['root'].'/'.$row['id'])->assertNotFound();
        $this->getJson($s['root'].'/'.$row['id'].'/download')->assertNotFound();
        $org = Organization::factory()->create();
        $this->grantPeopleAccess($org, $otherUser, ['reporting.export', 'reporting.direction', 'sports.read']);
        $this->getJson('/api/v1/tenants/'.$org->id.'/exports/'.$row['id'].'/download')->assertNotFound();
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_identical_reports_from_two_tenants_have_separate_files_and_contents(): void
    {
        $s = $this->scenario();
        Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'active', 'pseudonym' => 'Alpha']);
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $org = Organization::factory()->create(['timezone' => 'UTC']);
        $this->grantPeopleAccess($org, $s['user'], ['reporting.export', 'reporting.direction', 'sports.read']);
        Person::factory()->create(['organization_id' => $org->id, 'status' => 'active', 'pseudonym' => 'Beta']);
        $other = [...$s, 'org' => $org, 'root' => '/api/v1/tenants/'.$org->id.'/exports'];
        $next = $this->requestExport($other);
        $this->process($other, $next);
        $csv = $this->get($other['root'].'/'.$next['id'].'/download')->assertOk()->getContent();
        $this->assertIsString($csv);
        $this->assertStringContainsString('Beta', $csv);
        $this->assertStringNotContainsString('Alpha', $csv);
        $this->assertDatabaseCount('tenant_artifacts', 2);
        Queue::assertPushed(BuildReportExport::class, 2);
    }

    public function test_revoked_source_permissions_hide_list_metadata_and_block_download_and_processing(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $s['assignment']->role->forceFill(['permissions' => ['reporting.export', 'reporting.direction']])->save();
        $this->getJson($s['root'])->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.meta.total', 0);
        $this->getJson($s['root'].'/'.$row['id'].'/download')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->process($s, $row);
    }

    public function test_worker_middleware_rechecks_revoked_membership_without_creating_a_file(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        OrganizationMembership::query()->whereKey($s['assignment']->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        Queue::fake()->except([BuildReportExport::class]);
        Bus::dispatchSync(new BuildReportExport($this->personContext($s['org'], $s['user'])->toJobContext(), $row['id']));
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->assertDatabaseHas('report_exports', ['id' => $row['id'], 'status' => 'failed']);
        Queue::assertNothingPushed();
    }

    public function test_source_change_invalidates_download_without_synchronous_regeneration(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'active']);
        $this->getJson($s['root'].'/'.$row['id'].'/download')->assertConflict()->assertJsonPath('code', 'REPORT_EXPORT_STALE');
        $this->assertDatabaseCount('tenant_artifacts', 1);
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_expiration_denies_download_and_cleanup_releases_only_expired_files_even_after_membership_revocation(): void
    {
        $s = $this->scenario();
        $context = $this->personContext($s['org'], $s['user']);
        $permanent = app(TenantArtifacts::class)->store($context, 'keep.txt', 'permanent');
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $this->travel(60)->minutes();
        $this->getJson($s['root'].'/'.$row['id'])->assertOk()->assertJsonPath('data.state', 'expired');
        $this->getJson($s['root'].'/'.$row['id'].'/download')->assertConflict()->assertJsonPath('code', 'REPORT_EXPORT_EXPIRED');
        OrganizationMembership::query()->whereKey($s['assignment']->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        $this->assertSame(0, app(TenantArtifactService::class)->expireBatch());
        $this->assertSame(0, app(TenantArtifactService::class)->expireBatch());
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertDatabaseHas('tenant_artifacts', ['id' => $permanent->id]);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $s['org']->id, 'bytes' => 9]);
    }

    public function test_disabled_or_synchronous_queues_refuse_requests(): void
    {
        $s = $this->scenario();
        config(['report_exports.processing_enabled' => false]);
        $this->postJson($s['root'], $s['input'])->assertConflict()->assertJsonPath('code', 'REPORT_EXPORT_DISABLED');
        config(['report_exports.processing_enabled' => true, 'queue.default' => 'sync']);
        $this->postJson($s['root'], $s['input'])->assertConflict();
        $this->assertDatabaseCount('report_exports', 0);
        Queue::assertNothingPushed();
    }

    public function test_period_input_confirmation_and_report_catalogue_are_closed(): void
    {
        $s = $this->scenario();
        foreach ([['confirm' => false], ['from' => '2026-01-01'], ['to' => '2026-10-20'], ['from' => 'invalid'], ['request_key' => 'bad'],
            ['card' => 'matches'], ['card' => 'budgets'], ['actor_user_id' => 42], ['artifact_id' => (string) Str::uuid()], ['format' => 'html']] as $change) {
            $this->postJson($s['root'], [...$s['input'], ...$change])->assertUnprocessable();
        }
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $s['org']->id, 'actor_user_id' => $s['user']->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader('X-Tenant-Revision', $revision)->postJson($s['root'], $s['input'])->assertConflict();
        $this->assertDatabaseCount('report_exports', 0);
        Queue::assertNothingPushed();
    }

    public function test_permission_is_required_independently_of_the_dashboard_read_permission(): void
    {
        $s = $this->scenario(['reporting.direction', 'sports.read']);
        $this->getJson($s['root'])->assertForbidden();
        $this->postJson($s['root'], $s['input'])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_row_quota_is_checked_before_any_file_is_stored(): void
    {
        $s = $this->scenario();
        Person::factory()->count(3)->create(['organization_id' => $s['org']->id, 'status' => 'active']);
        config(['tenancy.limit_overrides.'.$s['org']->id.'.export_rows' => 2]);
        $row = $this->requestExport($s);
        try {
            $this->process($s, $row);
            $this->fail('Export quota must refuse the complete report.');
        } catch (TenantLimitExceededException) {
            $this->assertDatabaseCount('tenant_artifacts', 0);
        }
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_dispatch_failure_is_visible_without_disclosing_transport_details(): void
    {
        $s = $this->scenario();
        $this->mock(ReportExportQueue::class)->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Secret transport'));
        $r = $this->postJson($s['root'], $s['input'])->assertConflict()->assertJsonPath('code', 'REPORT_EXPORT_DISPATCH_FAILED');
        $this->assertStringNotContainsString('Secret transport', (string) $r->getContent());
        $this->getJson($s['root'])->assertOk()->assertJsonPath('data.items.0.state', 'failed');
        Queue::assertNothingPushed();
    }

    public function test_active_request_limit_applies_across_the_tenant_and_list_is_paginated(): void
    {
        $s = $this->scenario();
        for ($i = 0; $i < 10; $i++) {
            $this->requestExport([...$s, 'input' => [...$s['input'], 'request_key' => (string) Str::uuid()]]);
        }
        $this->postJson($s['root'], [...$s['input'], 'request_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'REPORT_EXPORT_LIMIT');
        $this->getJson($s['root'])->assertOk()->assertJsonCount(10, 'data.items')->assertJsonPath('data.meta.total', 10);
        $this->travel(61)->minutes();
        $this->requestExport([...$s, 'input' => [...$s['input'], 'request_key' => (string) Str::uuid()]]);
        $this->getJson($s['root'].'?page=2')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.meta.total', 11);
        Queue::assertPushed(BuildReportExport::class, 11);
    }

    public function test_completed_export_identity_and_history_cannot_be_rewritten_in_sql(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        foreach ([fn () => DB::table('report_exports')->update(['status' => 'queued']), fn () => DB::table('report_exports')->delete()] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Export history is immutable.');
            } catch (QueryException) {
                $this->assertDatabaseHas('report_exports', ['id' => $row['id'], 'status' => 'completed']);
            }
        }
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $migration = require database_path('migrations/2026_10_21_224633_create_report_exports_and_artifact_expiry.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_expiry_command_is_registered_and_does_not_touch_permanent_files(): void
    {
        $s = $this->scenario();
        app(TenantArtifacts::class)->store($this->personContext($s['org'], $s['user']), 'permanent.txt', 'keep');
        $command = $this->artisan('tenancy:expire-artifacts');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
        $this->assertDatabaseCount('tenant_artifacts', 1);
        Queue::assertNothingPushed();
    }

    #[TestWith(['unlinked_people', 'administration'])]
    #[TestWith(['people_imports', 'administration'])]
    #[TestWith(['overdue_deliverables', 'direction'])]
    #[TestWith(['registration_due', 'manager'])]
    public function test_other_organization_reports_use_their_owned_projections(string $card, string $view): void
    {
        $s = $this->scenario(['reporting.export', 'reporting.direction', 'reporting.administration', 'reporting.manager',
            'sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'partnerships.read', 'sports.competitions.read', 'sports.registrations.read']);
        $s['input'] = [...$s['input'], 'card' => $card, 'view' => $view];
        if ($card === 'unlinked_people') {
            Person::factory()->create(['organization_id' => $s['org']->id, 'user_id' => null, 'first_name' => 'Confidential identity']);
        } elseif ($card === 'people_imports') {
            PersonImport::factory()->create(['organization_id' => $s['org']->id]);
        } elseif ($card === 'overdue_deliverables') {
            $sponsor = Sponsor::factory()->create(['organization_id' => $s['org']->id]);
            $agreement = SponsorshipAgreement::factory()->create(['sponsor_id' => $sponsor->id]);
            $campaign = SponsorshipCampaign::factory()->create(['agreement_id' => $agreement->id]);
            SponsorshipDeliverable::factory()->create(['campaign_id' => $campaign->id, 'due_at' => now()->subHour()]);
        } else {
            $competition = ExternalCompetition::factory()->create(['organization_id' => $s['org']->id]);
            app(CompetitionRegistrationRepository::class)->append(['id' => (string) Str::uuid(),
                'organization_id' => $s['org']->id, 'competition_id' => $competition->id, 'number' => 1, 'payload' => [],
                'reason' => 'Échéance contrôlée', 'actor_user_id' => $s['user']->id, 'next_due_at' => '2026-10-25 23:30:00', 'checked_at' => null]);
        }
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $this->getJson($s['root'].'/'.$row['id'])->assertOk()->assertJsonPath('data.row_count', 1);
        $csv = $this->get($s['root'].'/'.$row['id'].'/download')->assertOk()->getContent();
        $this->assertIsString($csv);
        $this->assertStringContainsString($card, $csv);
        $this->assertStringNotContainsString('Confidential identity', $csv);
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_hard_row_limit_refuses_to_truncate_a_large_report(): void
    {
        $s = $this->scenario();
        Person::factory()->count(501)->create(['organization_id' => $s['org']->id, 'status' => 'active']);
        $row = $this->requestExport($s);
        try {
            $this->process($s, $row);
            $this->fail('The report must not be truncated.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('REPORT_EXPORT_LIMIT', $exception->errorCode());
            $this->assertDatabaseCount('tenant_artifacts', 0);
        }
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_contract_report_uses_the_inclusive_local_period_without_financial_or_document_contents(): void
    {
        $this->scenario();
        [$org, $user, $contract, $context] = $this->documentScenario(['reporting.export', 'reporting.direction', 'administrative.read', 'administrative.write', 'administrative.validate']);
        $contract->forceFill(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-25'])->save();
        $this->declareContractSignature($org, $contract, $context);
        $this->actingAsWithMfa($user);
        $s = ['org' => $org, 'user' => $user, 'root' => '/api/v1/tenants/'.$org->id.'/exports',
            'input' => ['card' => 'expiring_contracts', 'view' => 'direction', 'from' => '2026-10-21', 'to' => '2026-10-25', 'confirm' => true, 'request_key' => (string) Str::uuid()]];
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $this->getJson($s['root'].'/'.$row['id'])->assertOk()->assertJsonPath('data.row_count', 1);
        $csv = $this->get($s['root'].'/'.$row['id'].'/download')->assertOk()->getContent();
        $this->assertIsString($csv);
        $this->assertStringContainsString('2026-10-25', $csv);
        $this->assertStringContainsString($contract->reference, $csv);
        $this->assertStringNotContainsString('Camille Martin', $csv);
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_source_mutation_during_generation_discards_the_inconsistent_snapshot(): void
    {
        $s = $this->scenario();
        Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'active']);
        $row = $this->requestExport($s);
        $watch = true;
        DB::listen(function (QueryExecuted $query) use ($s, &$watch): void {
            if ($watch && str_starts_with(strtolower($query->sql), 'select') && preg_match('/from ["\x60]people["\x60]/', $query->sql)) {
                $watch = false;
                Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'active']);
            }
        });
        try {
            $this->process($s, $row);
            $this->fail('A source change must invalidate generation.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('REPORT_EXPORT_STALE', $exception->errorCode());
            $this->assertDatabaseCount('tenant_artifacts', 0);
        } finally {
            $watch = false;
        }
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_corrupted_private_file_is_never_downloaded(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        Storage::disk('local')->put(Storage::disk('local')->allFiles()[0], 'corrupted');
        $this->getJson($s['root'].'/'.$row['id'].'/download')->assertConflict()->assertJsonPath('code', 'REPORT_EXPORT_UNAVAILABLE');
        $this->assertSame(2, DB::table('organization_sensitive_access_events')->where('target_id', $row['id'])->count());
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_replayed_failed_job_cannot_poison_a_completed_export(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        app(ReportExportService::class)->fail($s['org']->id, $s['user']->id, $row['id']);
        $this->getJson($s['root'].'/'.$row['id'])->assertOk()->assertJsonPath('data.state', 'completed');
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_export_worker_lock_prevents_overlapping_generations(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $context = $this->personContext($s['org'], $s['user']);
        try {
            app(TenantCache::class)->synchronized($context, 'report-export:'.$row['id'], 120,
                fn () => $this->process($s, $row));
            $this->fail('Overlapping generation must not acquire the same lock.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('TENANT_RESOURCE_BUSY', $exception->errorCode());
            $this->assertDatabaseCount('tenant_artifacts', 0);
            $this->assertDatabaseHas('report_exports', ['id' => $row['id'], 'status' => 'queued']);
        }
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_unauthenticated_requests_are_refused_before_any_export_lookup(): void
    {
        $org = (string) Str::uuid();
        $this->getJson('/api/v1/tenants/'.$org.'/exports')->assertUnauthorized();
        $this->postJson('/api/v1/tenants/'.$org.'/exports', [])->assertUnauthorized();
        $this->getJson('/api/v1/tenants/'.$org.'/exports/'.Str::uuid().'/download')->assertUnauthorized();
        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_expired_artifact_is_unreadable_before_the_cleanup_runs(): void
    {
        $s = $this->scenario();
        $context = $this->personContext($s['org'], $s['user']);
        $artifact = app(TenantArtifacts::class)->store($context, 'expiring.txt', 'private', now()->addSecond()->toImmutable());
        $this->travel(2)->seconds();
        $this->expectException(ModelNotFoundException::class);
        app(TenantArtifacts::class)->read($context, $artifact->id);
    }

    public function test_failed_cleanup_keeps_the_quota_and_can_be_retried_without_touching_permanent_files(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $this->process($s, $row);
        $this->travel(61)->minutes();
        $originalFactory = app(Factory::class);
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        $this->mock(Factory::class)->shouldReceive('disk')->with('local')->once()->andReturn($disk);
        $bytes = DB::table('tenant_storage_usage')->where('organization_id', $s['org']->id)->value('bytes');
        $this->assertSame(1, app(TenantArtifactService::class)->expireBatch());
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $s['org']->id, 'bytes' => $bytes]);
        $this->app->instance(Factory::class, $originalFactory);
        $this->assertSame(0, app(TenantArtifactService::class)->expireBatch());
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->assertDatabaseHas('tenant_storage_usage', ['organization_id' => $s['org']->id, 'bytes' => 0]);
        Queue::assertPushed(BuildReportExport::class, 1);
    }

    public function test_duplicate_queue_delivery_does_not_fail_the_job_that_holds_the_generation_lock(): void
    {
        $s = $this->scenario();
        $row = $this->requestExport($s);
        $context = $this->personContext($s['org'], $s['user']);
        Queue::fake()->except([BuildReportExport::class]);
        app(TenantCache::class)->synchronized($context, 'report-export:'.$row['id'], 120,
            fn () => Bus::dispatchSync(new BuildReportExport($context->toJobContext(), $row['id'])));
        $this->assertDatabaseHas('report_exports', ['id' => $row['id'], 'status' => 'queued']);
        $this->assertDatabaseCount('tenant_artifacts', 0);
        Queue::assertNothingPushed();
    }
}
