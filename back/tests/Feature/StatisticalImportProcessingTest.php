<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Performance\Application\Contracts\StatisticalImportQueue;
use App\Modules\Performance\Application\Services\StatisticalImportConfirmation;
use App\Modules\Performance\Application\Services\StatisticalImportProcessor;
use App\Modules\Performance\Application\Services\StatisticalImportService;
use App\Modules\Performance\Application\Validation\MetricCatalogueInput;
use App\Modules\Performance\Domain\Models\ObservationRevision;
use App\Modules\Performance\Domain\Models\StatisticalImportExecution;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Performance\Infrastructure\Queue\ProcessStatisticalImport;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class StatisticalImportProcessingTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMetricCatalogue, ProvidesObservations, ProvidesPeopleAccess;

    /** @return array<string,mixed> */
    private function scenario(bool $fakeQueue = true): array
    {
        $this->freezeTime();
        config(['statistical_imports.processing_enabled' => true]);
        $s = $this->observationScenario();
        $role = $s['assignment']->role;
        $role->forceFill(['permissions' => [...$role->permissions, 'sports.statistics.import']])->save();
        if ($fakeQueue) {
            Queue::fake([ProcessStatisticalImport::class]);
        }
        Storage::fake('local');

        return [...$s, 'imports' => '/api/v1/tenants/'.$s['org']->id.'/statistical-imports'];
    }

    /** @param array<string,mixed> $s
     * @param  list<array<string,mixed>>|null  $rows
     */
    private function preview(array $s, ?array $rows = null, string $delimiter = ','): string
    {
        $rows ??= [$s['input']];
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot allocate test CSV stream.');
        }
        fputcsv($stream, array_keys($rows[0]), $delimiter, '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), $delimiter, '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return app(StatisticalImportService::class)->upload($this->personContext($s['org'], $s['writer']), $contents, $delimiter)->id;
    }

    /** @param array<string,mixed> $s */
    private function confirm(array $s, string $id): string
    {
        app(StatisticalImportConfirmation::class)->confirm($this->personContext($s['org'], $s['writer']), $id,
            ['version' => $id, 'confirm' => true, 'acknowledge_rejected' => true]);

        return StatisticalImportExecution::query()->where('import_id', $id)->value('version');
    }

    /** @param array<string,mixed> $s */
    private function process(array $s, string $id, string $version): bool
    {
        return app(StatisticalImportProcessor::class)->process($this->personContext($s['org'], $s['writer']), $id, $version);
    }

    public function test_http_confirmation_enqueues_private_context_and_does_not_write_before_worker(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.can_confirm', true);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertOk()
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.processed_count', 0)->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.can_resume', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('statistical_import_executions', 1);
        $this->assertDatabaseCount('statistical_observations', 0);
        Queue::assertPushed(ProcessStatisticalImport::class, fn ($job) => $job->context->tenantId === $s['org']->id
            && $job->context->actorUserId === $s['writer']->id && $job->queue === 'reports' && $job->afterCommit === true);
        Queue::assertPushed(ProcessStatisticalImport::class, 1);
    }

    public function test_explicit_confirmation_is_required_and_rejected_rows_need_acknowledgment(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s, [$s['input'], [...$s['input'], 'value' => '=SUM(1,2)']]);
        $this->postJson($s['imports'].'/'.$id.'/confirm', [])->assertUnprocessable()->assertJsonValidationErrors(['version', 'confirm']);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => false])->assertUnprocessable();
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'STAT_IMPORT_REJECTED');
        $version = $this->confirm($s, $id);
        $this->assertFalse($this->process($s, $id, $version));
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.counts.created', 1)
            ->assertJsonPath('data.counts.rejected', 1)->assertJsonPath('data.rows.data.1.outcome.code', 'PREVIEW_INVALID');
    }

    public function test_disabled_processing_and_all_invalid_previews_never_enqueue(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s, [[...$s['input'], 'value' => 'invalid']]);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'STAT_IMPORT_EMPTY');
        $id = $this->preview($s);
        config(['statistical_imports.processing_enabled' => false]);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'STAT_IMPORT_DISABLED');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('statistical_import_executions', 0);
    }

    public function test_closed_confirmation_payload_and_wrong_version_are_refused(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true, 'organization_id' => $s['org']->id])->assertUnprocessable();
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => (string) Str::uuid(), 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'STAT_IMPORT_VERSION');
        $this->assertDatabaseCount('statistical_import_executions', 0);
    }

    public function test_imports_zero_missing_and_exact_decimals_and_retry_does_not_duplicate(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s, [$s['input'], [...$s['input'], 'source_label' => 'Source B', 'availability' => 'unavailable', 'value' => null, 'missing_reason' => 'Mesure indisponible.'],
            [...$s['input'], 'source_label' => 'Source C', 'value' => '123456789.123456']]);
        $version = $this->confirm($s, $id);
        $this->assertFalse($this->process($s, $id, $version));
        $this->assertFalse($this->process($s, $id, $version));
        $this->assertDatabaseCount('statistical_observations', 3);
        $this->assertDatabaseCount('observation_revisions', 3);
        $this->assertDatabaseCount('statistical_import_receipts', 3);
        $records = StatisticalObservation::query()->with('current')->get()->mapWithKeys(fn ($record) => [$record->source_label => $record->current?->value]);
        $this->assertSame('0', $records[$s['input']['source_label']]);
        $this->assertNull($records['Source B']);
        $this->assertSame('123456789.123456', $records['Source C']);
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processed_count', 3)->assertJsonPath('data.counts.created', 3)->assertJsonPath('data.can_resume', false);
        $this->assertSame(['import'], StatisticalObservation::query()->distinct()->pluck('acquired_via')->all());
    }

    public function test_other_files_and_actors_share_deduplication_but_never_overwrite_values(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->process($s, $id, $this->confirm($s, $id));
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $other, ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import']);
        $s['writer'] = $other;
        $id = $this->preview($s, [[...$s['input'], 'value' => '0.000000', 'reason' => 'Autre justification pour la même mesure.']], ';');
        $this->process($s, $id, $this->confirm($s, $id));
        $this->actingAsWithMfa($other);
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.counts.duplicate', 1);
        $id = $this->preview($s, [[...$s['input'], 'value' => '42']]);
        $this->process($s, $id, $this->confirm($s, $id));
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.rows.data.0.outcome.code', 'OBSERVATION_CONFLICT');
        $this->assertDatabaseCount('statistical_observations', 1);
        $this->assertDatabaseCount('observation_revisions', 1);
        $this->assertSame('0', ObservationRevision::query()->firstOrFail()->value);
    }

    public function test_deleting_a_grant_after_confirmation_stops_the_worker(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $version = $this->confirm($s, $id);
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        try {
            $this->process($s, $id, $version);
            $this->fail('A revoked grant must stop processing.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('statistical_observations', 0);
        }
        app(StatisticalImportProcessor::class)->failed($s['org']->id, $s['writer']->id, $id, $version);
        $this->assertDatabaseHas('statistical_import_executions', ['import_id' => $id, 'status' => 'failed', 'processed_count' => 0]);
        $this->postJson($s['imports'].'/'.$id.'/resume', ['version' => $version, 'confirm' => true])->assertForbidden();
    }

    public function test_references_are_revalidated_at_confirmation_and_execution(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $s['person']->forceFill(['status' => 'former'])->save();
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertConflict()->assertJsonPath('code', 'STAT_IMPORT_STALE');
        $s['person']->forceFill(['status' => 'active'])->save();
        $version = $this->confirm($s, $id);
        $s['person']->forceFill(['status' => 'former'])->save();
        $this->process($s, $id, $version);
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.rows.data.0.outcome.code', 'REFERENCE_CHANGED');
        $this->assertDatabaseCount('statistical_observations', 0);
    }

    public function test_partial_chunk_resume_rejects_old_jobs_and_preserves_committed_rows(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s, array_map(fn ($n) => [...$s['input'], 'source_label' => 'Source '.$n], range(1, 12)));
        $version = $this->confirm($s, $id);
        $this->assertTrue($this->process($s, $id, $version));
        $this->assertDatabaseCount('statistical_observations', 10);
        app(StatisticalImportProcessor::class)->failed($s['org']->id, $s['writer']->id, $id, $version);
        $response = $this->postJson($s['imports'].'/'.$id.'/resume', ['version' => $version, 'confirm' => true])->assertOk()
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.processed_count', 10);
        $nextVersion = $response->json('data.version');
        $this->assertNotSame($version, $nextVersion);
        $this->assertFalse($this->process($s, $id, $version));
        app(StatisticalImportProcessor::class)->failed($s['org']->id, $s['writer']->id, $id, $version);
        $this->assertFalse($this->process($s, $id, $nextVersion));
        $this->assertDatabaseCount('statistical_observations', 12);
        $this->assertDatabaseCount('observation_revisions', 12);
        $this->postJson($s['imports'].'/'.$id.'/resume', ['version' => $version, 'confirm' => true])->assertConflict();
        $this->getJson($s['imports'].'/'.$id.'?page=2')->assertOk()->assertJsonPath('data.rows.data.0.outcome.status', 'created');
    }

    public function test_sql_failure_rolls_back_the_current_line_and_resume_does_not_lose_prior_results(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s, [$s['input'], [...$s['input'], 'source_label' => 'Source B']]);
        $version = $this->confirm($s, $id);
        $written = 0;
        ObservationRevision::created(function () use (&$written): void {
            if (++$written === 2) {
                throw new \RuntimeException('Injected interruption with no private payload');
            }
        });
        try {
            $this->process($s, $id, $version);
            $this->fail('The injected fault must interrupt the run.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected interruption with no private payload', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.ObservationRevision::class);
        }
        $this->assertDatabaseCount('statistical_observations', 1);
        $this->assertDatabaseCount('statistical_import_receipts', 1);
        $this->assertDatabaseHas('statistical_import_executions', ['import_id' => $id, 'processed_count' => 1]);
        $this->process($s, $id, $version);
        $this->assertDatabaseCount('statistical_observations', 2);
        $this->assertDatabaseCount('statistical_import_receipts', 2);
    }

    public function test_cross_actor_cross_tenant_and_reader_cannot_confirm_or_resume(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $other, $s['assignment']->role->permissions);
        $this->actingAsWithMfa($other);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertNotFound();
        $organization = Organization::factory()->create();
        $this->grantPeopleAccess($organization, $other, $s['assignment']->role->permissions);
        $this->postJson('/api/v1/tenants/'.$organization->id.'/statistical-imports/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertNotFound();
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('statistical_import_executions', 0);
    }

    public function test_dispatch_failure_keeps_a_resumable_confirmation_without_csv_in_failure(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->mock(StatisticalImportQueue::class)->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Sensitive internal transport failure'));
        $response = $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'STAT_IMPORT_DISPATCH_FAILED');
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Sensitive internal', $body);
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.can_resume', true)->assertJsonPath('data.status', 'queued');
        $this->assertDatabaseCount('statistical_observations', 0);
    }

    public function test_execution_catalog_and_encrypted_results_preserve_private_exports(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->process($s, $id, $this->confirm($s, $id));
        $raw = DB::table('statistical_import_executions')->where('import_id', $id)->firstOrFail();
        $this->assertStringNotContainsString('created', $raw->results);
        $catalog = app(PortabilityRepository::class);
        $catalog->assertCatalog();
        $backup = iterator_to_array($catalog->rows($s['org']->id, 100));
        $this->assertCount(1, array_filter($backup, fn ($row) => $row['table'] === 'statistical_import_receipts'));
        $this->assertStringNotContainsString('Note privée', json_encode($backup, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_19_175141_create_statistical_import_executions.php'))->down();
    }

    public function test_real_queue_restores_tenant_context_and_finishes_the_import(): void
    {
        $s = $this->scenario(false);
        config(['queue.default' => 'sync']);
        $id = $this->preview($s);
        $this->confirm($s, $id);
        $this->assertDatabaseHas('statistical_import_executions', ['import_id' => $id, 'status' => 'completed']);
        $this->assertDatabaseCount('statistical_observations', 1);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_unconfirmed_and_wrong_generation_jobs_create_nothing(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->assertFalse($this->process($s, $id, (string) Str::uuid()));
        $this->confirm($s, $id);
        $this->assertFalse($this->process($s, $id, (string) Str::uuid()));
        $this->assertDatabaseCount('statistical_observations', 0);
    }

    public function test_pause_flag_interrupts_processing_before_any_line_and_allows_explicit_resume(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $version = $this->confirm($s, $id);
        config(['statistical_imports.processing_enabled' => false]);
        try {
            $this->process($s, $id, $version);
            $this->fail('Disabled processing must not create an observation.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('STAT_IMPORT_DISABLED', $exception->errorCode());
        }
        $this->assertDatabaseCount('statistical_observations', 0);
        $this->assertDatabaseHas('statistical_import_executions', ['import_id' => $id, 'processed_count' => 0]);
    }

    public function test_sql_protects_receipts_cross_tenant_links_and_execution_identity(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->process($s, $id, $this->confirm($s, $id));
        $other = Organization::factory()->create();
        foreach (['receipt_update', 'receipt_delete', 'foreign_observation', 'execution_tenant', 'execution_count', 'execution_delete'] as $case) {
            try {
                DB::transaction(function () use ($case, $id, $other): void {
                    $receipt = DB::table('statistical_import_receipts')->firstOrFail();
                    match ($case) {
                        'receipt_update' => DB::table('statistical_import_receipts')->update(['payload_hash' => str_repeat('a', 64)]),
                        'receipt_delete' => DB::table('statistical_import_receipts')->delete(),
                        'foreign_observation' => DB::table('statistical_import_receipts')->insert([...get_object_vars($receipt), 'id' => (string) Str::uuid(), 'organization_id' => $other->id, 'identity_key' => str_repeat('b', 64)]),
                        'execution_tenant' => DB::table('statistical_import_executions')->where('import_id', $id)->update(['organization_id' => $other->id]),
                        'execution_count' => DB::table('statistical_import_executions')->where('import_id', $id)->update(['processed_count' => 0]),
                        default => DB::table('statistical_import_executions')->where('import_id', $id)->delete(),
                    };
                });
                $this->fail('Import history must remain tenant-bound and retained.');
            } catch (QueryException) {
                $this->assertDatabaseCount('statistical_import_receipts', 1);
                $this->assertDatabaseHas('statistical_import_executions', ['organization_id' => $s['org']->id, 'processed_count' => 1]);
            }
        }
    }

    public function test_distinct_tenants_have_independent_receipts_and_private_results(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $this->process($s, $id, $this->confirm($s, $id));
        $other = Organization::factory()->create();
        OrganizationGame::factory()->create(['organization_id' => $other->id, 'game_id' => $s['game']->id, 'enabled' => true]);
        $this->grantPeopleAccess($other, $s['writer'], $s['assignment']->role->permissions);
        $person = Person::factory()->create(['organization_id' => $other->id]);
        $s['org'] = $other;
        $s['input']['person_id'] = $person->id;
        $id = $this->preview($s);
        $this->process($s, $id, $this->confirm($s, $id));
        $this->assertDatabaseCount('statistical_import_receipts', 2);
        $this->assertDatabaseCount('statistical_observations', 2);
    }

    public function test_confirmation_requires_current_tenant_confirmation_and_mfa(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => [
            'organization_id' => $s['org']->id, 'actor_user_id' => $s['writer']->id,
            'revision' => $revision, 'confirmed' => false,
        ]])->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['version' => $id, 'confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('statistical_import_executions', 0);
        Queue::assertNothingPushed();
    }

    public function test_metric_version_changes_refuse_confirmation_and_reject_already_queued_lines(): void
    {
        $s = $this->scenario();
        $queued = $this->preview($s);
        $version = $this->confirm($s, $queued);
        $unconfirmed = $this->preview($s, null, ';');
        $this->actingAsWithMfa($s['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->assertOk()->json('meta');
        $input = [...$s['metric'], 'formula' => 'Nouvelle formule métier explicitement documentée.',
            'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'],
            'confirm_global' => true, 'reason' => 'Révision de la définition globale avant acquisition.'];
        $this->patchJson('/api/v1/metric-catalogue/'.$s['metric']['id'],
            array_intersect_key($input, MetricCatalogueInput::rules('update')))->assertOk();
        $this->actingAsWithMfa($s['writer']);
        $this->postJson($s['imports'].'/'.$unconfirmed.'/confirm', ['version' => $unconfirmed, 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'STAT_IMPORT_STALE');
        $this->process($s, $queued, $version);
        $this->getJson($s['imports'].'/'.$queued)->assertOk()->assertJsonPath('data.rows.data.0.outcome.code', 'REFERENCE_CHANGED');
        $this->assertDatabaseCount('statistical_observations', 0);
    }

    public function test_validation_failure_after_writing_is_retried_instead_of_rejecting_the_line(): void
    {
        $s = $this->scenario();
        $id = $this->preview($s);
        $version = $this->confirm($s, $id);
        ObservationRevision::created(fn () => throw ValidationException::withMessages(['audit' => 'Audit temporarily rejected.']));
        try {
            $this->process($s, $id, $version);
            $this->fail('A write failure must roll back the line for retry.');
        } catch (ValidationException $exception) {
            $this->assertSame(['audit' => ['Audit temporarily rejected.']], $exception->errors());
        } finally {
            Event::forget('eloquent.created: '.ObservationRevision::class);
        }
        $this->assertDatabaseCount('statistical_observations', 0);
        $this->assertDatabaseCount('statistical_import_receipts', 0);
        $this->assertDatabaseHas('statistical_import_executions', ['import_id' => $id, 'processed_count' => 0]);
        $this->process($s, $id, $version);
        $this->assertDatabaseCount('statistical_observations', 1);
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertJsonPath('data.counts.created', 1);
    }
}
