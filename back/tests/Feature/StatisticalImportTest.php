<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Performance\Application\Services\StatisticalImportService;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class StatisticalImportTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMetricCatalogue, ProvidesObservations, ProvidesPeopleAccess;

    /** @return array<string,mixed> */
    private function scenario(): array
    {
        $this->freezeTime();
        $s = $this->observationScenario();
        $role = $s['assignment']->role;
        $role->forceFill(['permissions' => [...$role->permissions, 'sports.statistics.import']])->save();
        Storage::fake('local');
        Queue::fake();

        return [...$s, 'imports' => '/api/v1/tenants/'.$s['org']->id.'/statistical-imports'];
    }

    /** @param list<array<string,mixed>> $rows */
    private function csv(array $rows, string $delimiter = ','): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot allocate CSV fixture stream.');
        }
        fputcsv($stream, array_keys($rows[0]), $delimiter, '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), $delimiter, '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    /** @param array<string,mixed> $s
     * @return TestResponse<Response>
     */
    private function upload(array $s, string $csv, string $delimiter = ','): TestResponse
    {
        return $this->post($s['imports'], ['file' => UploadedFile::fake()->createWithContent('statistics.csv', $csv), 'delimiter' => $delimiter], ['Accept' => 'application/json']);
    }

    public function test_preview_resolves_versions_preserves_zero_and_missing_and_never_creates_observations(): void
    {
        $s = $this->scenario();
        $csv = $this->csv([$s['input'], [...$s['input'], 'value' => null, 'availability' => 'unavailable', 'missing_reason' => 'Mesure absente.'], $s['input'], [...$s['input'], 'value' => '=SUM(1,2)']]);
        $response = $this->upload($s, $csv)->assertOk()->assertJsonPath('data.status', 'previewed')->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.valid_count', 2)->assertJsonPath('data.invalid_count', 2)
            ->assertJsonPath('data.rows.data.0.values.value', '0')->assertJsonPath('data.rows.data.1.values.value', null)
            ->assertJsonPath('data.rows.data.0.resolved.person_id', $s['person']->id)
            ->assertJsonPath('data.rows.data.0.resolved.metric_version', $s['metric']['version'])
            ->assertJsonPath('data.rows.data.2.status', 'invalid')->assertJsonPath('data.rows.data.3.status', 'invalid');
        $id = $response->json('data.id');
        $this->getJson($s['imports'].'/'.$id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($s['imports'].'/'.$id.'/confirm', ['confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('statistical_observations', 0);
        $this->assertDatabaseCount('observation_revisions', 0);
        $this->assertDatabaseCount('people', 1);
        Queue::assertNothingPushed();
        $raw = DB::table('statistical_imports')->where('id', $id)->firstOrFail();
        $this->assertStringNotContainsString('Note privée', $raw->preview);
        $this->assertStringNotContainsString('metric_id', $raw->headers);
        $stored = Storage::disk('local')->get('tenants/'.$s['org']->id.'/artifacts/'.$raw->artifact_id);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('Grille privée', $stored);
        $this->assertStringNotContainsString('Note privée', json_encode(DB::table('organization_audit_events')->get()->all(), JSON_THROW_ON_ERROR));
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $backup = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $this->assertStringNotContainsString('Note privée', json_encode($backup, JSON_THROW_ON_ERROR));
        $this->assertCount(1, array_filter($backup, fn ($row) => $row['table'] === 'statistical_imports'));
    }

    public function test_retry_is_scoped_to_actor_and_tenant_and_reserves_storage_once(): void
    {
        $s = $this->scenario();
        $csv = $this->csv([$s['input']]);
        $id = $this->upload($s, $csv)->assertOk()->json('data.id');
        $this->assertSame($id, $this->upload($s, $csv)->assertOk()->json('data.id'));
        $this->assertDatabaseCount('tenant_artifacts', 1);
        $this->assertDatabaseCount('statistical_imports', 1);
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $other, ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import']);
        $this->actingAsWithMfa($other);
        $this->getJson($s['imports'].'/'.$id)->assertNotFound();
        $this->getJson($s['imports'])->assertOk()->assertJsonPath('meta.total', 0);
        $new = $this->upload($s, $csv)->assertOk()->json('data.id');
        $this->assertNotSame($id, $new);
        $org = Organization::factory()->create();
        $this->grantPeopleAccess($org, $other, ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import']);
        $s['imports'] = '/api/v1/tenants/'.$org->id.'/statistical-imports';
        $this->getJson($s['imports'].'/'.$id)->assertNotFound();
        $this->getJson($s['imports'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->upload($s, $csv)->assertOk()->assertJsonPath('data.valid_count', 0);
        $this->assertDatabaseCount('statistical_imports', 3);
        $this->assertDatabaseCount('statistical_observations', 0);
    }

    public function test_policy_requires_all_five_explicit_organization_permissions(): void
    {
        $s = $this->scenario();
        $permissions = ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import'];
        $ctx = $this->personContext($s['org'], $s['writer']);
        $this->assertTrue(Gate::forUser($s['writer'])->allows('manage', [StatisticalImport::class, $ctx]));
        foreach ($permissions as $missing) {
            $role = $s['assignment']->role;
            $role->forceFill(['permissions' => array_values(array_diff($permissions, [$missing]))])->save();
            $this->assertFalse(Gate::forUser($s['writer'])->allows('manage', [StatisticalImport::class, $ctx]));
        }
        $this->upload($s, $this->csv([$s['input']]))->assertForbidden();
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['imports'])->assertForbidden();
        $this->actingAsWithMfa(User::query()->whereKey($s['org']->owner_user_id)->firstOrFail());
        $this->getJson($s['imports'])->assertForbidden();
        $this->actingAsWithMfa($s['operator']);
        $this->getJson($s['imports'])->assertNotFound();
    }

    public function test_revocation_is_rechecked_with_a_previously_resolved_context(): void
    {
        $s = $this->scenario();
        $context = $this->personContext($s['org'], $s['writer']);
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        $this->expectException(AuthorizationException::class);
        app(StatisticalImportService::class)->upload($context, $this->csv([$s['input']]), ',');
    }

    /** @return array<string,array{string}> */
    public static function badCsv(): array
    {
        return ['empty' => [''], 'encoding' => ["name\n\xFF"], 'quotes' => ["metric_id\n\"bad"], 'duplicate header' => ["metric_id,metric_id\n1,2"],
            'unknown schema' => ["person_id,organization_id\n1,2"], 'missing schema' => ["person_id,value\n1,0"],
            'columns limit' => [implode(',', range(1, 33))."\n".implode(',', range(1, 33))]];
    }

    #[DataProvider('badCsv')]
    public function test_invalid_csv_never_reserves_an_artifact(string $csv): void
    {
        $s = $this->scenario();
        $this->upload($s, $csv)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('statistical_imports', 0);
        $this->assertDatabaseCount('tenant_artifacts', 0);
    }

    public function test_bounded_files_and_rows_are_checked_on_direct_service_and_http(): void
    {
        $s = $this->scenario();
        $this->upload($s, $this->csv(array_fill(0, 101, $s['input'])))->assertUnprocessable();
        $this->upload($s, str_repeat('x', 1048577))->assertUnprocessable();
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->expectException(ValidationException::class);
        app(StatisticalImportService::class)->upload($this->personContext($s['org'], $s['writer']), str_repeat('x', 1048577), ',');
    }

    public function test_reference_errors_are_per_line_and_no_foreign_label_is_disclosed(): void
    {
        $s = $this->scenario();
        $foreign = Person::factory()->create(['pseudonym' => 'TOP_SECRET_PERSON']);
        $response = $this->upload($s, $this->csv([
            [...$s['input'], 'person_id' => $foreign->id],
            [...$s['input'], 'metric_version' => (string) Str::uuid()],
            [...$s['input'], 'source_kind' => 'assessment'],
            [...$s['input'], 'observed_on' => '2999-01-01'],
            [...$s['input'], 'value' => '1e3'],
        ]))->assertOk()->assertJsonPath('data.valid_count', 0)->assertJsonPath('data.invalid_count', 5);
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('TOP_SECRET_PERSON', $body);
        $this->assertDatabaseCount('statistical_observations', 0);
    }

    public function test_bom_semicolon_multiline_and_wrong_cell_counts_keep_source_line_numbers(): void
    {
        $s = $this->scenario();
        $csv = "\xEF\xBB\xBF".$this->csv([[...$s['input'], 'notes' => "Ligne un\nLigne deux"], [...$s['input'], 'value' => '42']], ';');
        $csv .= "cellule;isolée\n";
        $this->upload($s, $csv, ';')->assertOk()->assertJsonPath('data.valid_count', 2)->assertJsonPath('data.invalid_count', 1)
            ->assertJsonPath('data.rows.data.0.line', 2)->assertJsonPath('data.rows.data.1.line', 4)->assertJsonPath('data.rows.data.2.line', 5);
    }

    public function test_pagination_is_bounded_and_does_not_query_each_run(): void
    {
        $s = $this->scenario();
        $id = $this->upload($s, $this->csv(array_map(fn ($n) => [...$s['input'], 'value' => (string) $n], range(1, 11))))->assertOk()->json('data.id');
        $this->getJson($s['imports'].'/'.$id.'?page=2')->assertOk()->assertJsonCount(1, 'data.rows.data')->assertJsonPath('data.rows.meta.total', 11);
        $this->getJson($s['imports'].'/'.$id.'?page=0')->assertUnprocessable();
        $this->getJson($s['imports'].'?organization_id='.$s['org']->id)->assertUnprocessable();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($s['imports'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $one = count(DB::getQueryLog());
        $original = StatisticalImport::query()->whereKey($id)->firstOrFail();
        for ($i = 0; $i < 10; $i++) {
            $copy = $original->replicate()->forceFill(['fingerprint' => hash('sha256', (string) $i)]);
            $copy->save();
        }
        DB::flushQueryLog();
        $list = $this->getJson($s['imports'])->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 11);
        $this->assertSame($one, count(DB::getQueryLog()));
        $this->assertStringContainsString('/statistical-imports?page=2', $list->json('links.next'));
        DB::disableQueryLog();
    }

    public function test_match_visibility_is_reapplied_before_reading_a_saved_preview(): void
    {
        $s = $this->scenario();
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id, 'kind' => 'scrim', 'visibility' => 'organization', 'sharing_authorized_by' => $s['reader']->id, 'sharing_authorized_at' => now(), 'created_by' => $s['reader']->id]);
        $id = $this->upload($s, $this->csv([[...$s['input'], 'source_kind' => 'scrim', 'match_id' => $match->id]]))->assertOk()->assertJsonPath('data.valid_count', 1)->json('data.id');
        $match->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'version' => (string) Str::uuid()])->save();
        $this->getJson($s['imports'].'/'.$id)->assertNotFound();
    }

    public function test_failed_metadata_write_cleans_only_its_new_artifact(): void
    {
        $s = $this->scenario();
        StatisticalImport::created(fn () => throw new \RuntimeException('Injected preview failure'));
        try {
            $this->upload($s, $this->csv([$s['input']]))->assertServerError();
        } finally {
            Event::forget('eloquent.created: '.StatisticalImport::class);
        }
        $this->assertDatabaseCount('statistical_imports', 0);
        $this->assertDatabaseCount('tenant_artifacts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('tenants/'.$s['org']->id));
    }

    public function test_preview_sql_is_immutable_and_rollback_refuses_nonempty_history(): void
    {
        $s = $this->scenario();
        $id = $this->upload($s, $this->csv([$s['input']]))->assertOk()->json('data.id');
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $id): void {
                    $query = DB::table('statistical_imports')->where('id', $id);
                    $operation === 'delete' ? $query->delete() : $query->update(['preview' => 'rewritten']);
                });
                $this->fail('Preview history must be retained.');
            } catch (QueryException) {
                $this->assertDatabaseHas('statistical_imports', ['id' => $id]);
            }
        }
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_19_143154_create_statistical_import_previews.php'))->down();
    }

    public function test_sql_rejects_cross_tenant_artifact_and_invalid_counts(): void
    {
        $s = $this->scenario();
        $id = $this->upload($s, $this->csv([$s['input']]))->assertOk()->json('data.id');
        $run = StatisticalImport::query()->whereKey($id)->firstOrFail();
        $other = Organization::factory()->create();
        foreach ([['organization_id' => $other->id], ['row_count' => 101], ['valid_count' => 5]] as $change) {
            try {
                DB::transaction(fn () => $run->replicate()->forceFill([...$change, 'fingerprint' => hash('sha256', json_encode($change, JSON_THROW_ON_ERROR))])->save());
                $this->fail('Invalid preview reference/counts must fail at the database boundary.');
            } catch (QueryException) {
                $this->assertDatabaseCount('statistical_imports', 1);
            }
        }
    }

    public function test_mfa_identity_and_team_scope_do_not_grant_organization_imports(): void
    {
        $s = $this->scenario();
        $team = Team::factory()->create(['organization_id' => $s['org']->id]);
        $teamUser = User::factory()->withMfa()->create();
        $assignment = $this->grantPeopleAccess($s['org'], $teamUser, ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.import']);
        $assignment->forceFill(['status' => 'revoked'])->save();
        OrganizationRoleAssignment::factory()->create([
            'organization_id' => $s['org']->id, 'user_id' => $teamUser->id, 'membership_id' => $assignment->membership_id,
            'role_id' => $assignment->role_id, 'scope_type' => 'team', 'scope_id' => $team->id,
        ]);
        $this->actingAsWithMfa($teamUser);
        $this->getJson($s['imports'])->assertForbidden();
        $this->actingAsWithMfa($s['writer']);
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->getJson($s['imports'])->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson($s['imports'])->assertUnauthorized();
    }

    public function test_empty_migration_can_be_reversed_and_reapplied(): void
    {
        $this->assertDatabaseCount('statistical_imports', 0);
        $statisticalExecution = require database_path('migrations/2026_10_19_175141_create_statistical_import_executions.php');
        $statisticalCalculations = require database_path('migrations/2026_10_19_183908_create_statistical_calculations.php');
        $statisticalCalculations->down();
        $statisticalExecution->down();
        $migration = require database_path('migrations/2026_10_19_143154_create_statistical_import_previews.php');
        $migration->down();
        $migration->up();
        $statisticalExecution->up();
        $statisticalCalculations->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }
}
