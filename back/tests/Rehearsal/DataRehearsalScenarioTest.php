<?php

namespace Tests\Rehearsal;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Services\MatchService;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\People\Application\Services\PersonImportProcessor;
use App\Modules\People\Application\Services\PersonImportService;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Performance\Application\Services\StatisticalImportConfirmation;
use App\Modules\Performance\Application\Services\StatisticalImportProcessor;
use App\Modules\Performance\Application\Services\StatisticalImportService;
use App\Modules\Performance\Domain\Models\StatisticalImportExecution;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Teams\Application\Services\TeamService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class DataRehearsalScenarioTest extends TestCase
{
    use AuthenticatesWithMfa, ProvidesMetricCatalogue, ProvidesObservations, ProvidesPeopleAccess;

    protected function setUp(): void
    {
        if (getenv('UCG_DATA_REHEARSAL_CHILD') !== '1' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new RuntimeException('Rehearsal requires its isolated child process.');
        }
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('tenancy.cache_store'));
        config(['filesystems.artifacts' => 'data002', 'filesystems.disks.data002' => ['driver' => 'local', 'visibility' => 'private'],
            'statistical_imports.processing_enabled' => true, 'person_imports.chunk_size' => 1,
            'cache.default' => 'array', 'cache.limiter' => 'array', 'tenancy.cache_store' => 'array', 'tenancy.schedule_connection' => 'sync',
            'broadcasting.default' => 'null', 'retention.erasure.enabled' => false, 'retention.erasure.restore_mode' => false]);
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Storage::fake('data002');
    }

    public function test_full_rehearsal_reconciles_totals_samples_links_and_rollback(): void
    {
        $s = $this->baseline();
        $this->reversible('pipeline', function () use ($s): array {
            $people = $this->importPeople($s);
            $ctx = $this->context($s);
            $team = app(TeamService::class)->save($ctx, ['game_id' => $s['game']->id, 'slug' => 'fictif-alpha', 'season' => '2026', 'name' => 'FICTIF Alpha', 'kind' => 'first', 'status' => 'active']);
            $match = app(MatchService::class)->save($ctx, ['game_id' => $s['game']->id, 'title' => 'FICTIF Match', 'kind' => 'scrim', 'format' => 'bo1',
                'starts_at' => '2026-10-20T12:00:00+00:00', 'ends_at' => '2026-10-20T13:00:00+00:00', 'origin_timezone' => 'UTC',
                'responsible_person_id' => $people[0], 'participants' => [['kind' => 'team', 'source_id' => $team->id], ['kind' => 'person', 'source_id' => $people[1]]]]);
            $contract = app(ContractService::class)->save($ctx, ['reference' => 'fictif-player-01', 'type' => 'player', 'title' => 'FICTIF Contrat',
                'responsible_person_id' => $people[0], 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-01',
                'parties' => [['kind' => 'person', 'person_id' => $people[1], 'name' => 'FICTIF Beta']]]);
            $available = [...$s['input'], 'person_id' => $people[0], 'source_label' => 'FICTIF zero', 'value' => '0.000000'];
            $missing = [...$s['input'], 'person_id' => $people[1], 'source_label' => 'FICTIF absent', 'availability' => 'unavailable', 'value' => null, 'missing_reason' => 'FICTIF source absente'];
            $summary = $this->importStatistics($s, [$available, $missing]);
            $this->assertSame(2, $summary['counts']['created']);
            $records = StatisticalObservation::query()->where('organization_id', $ctx->organizationId)->with('current')->get();
            $this->assertCount(2, $records);
            $missingRecord = $records->firstWhere('person_id', $people[1]);
            $zeroRecord = $records->firstWhere('person_id', $people[0]);
            $this->assertInstanceOf(StatisticalObservation::class, $missingRecord);
            $this->assertInstanceOf(StatisticalObservation::class, $zeroRecord);
            $this->assertNull($missingRecord->current?->value);
            $this->assertSame('0', $zeroRecord->current?->value);
            $this->assertSame($ctx->organizationId, $team->organization_id);
            $this->assertSame($people[0], $match->responsible_person_id);
            $this->assertSame($team->id, $match->participants()->where('kind', 'team')->firstOrFail()->sourceId());
            $this->assertSame($people[1], $contract->parties()->firstOrFail()->person_id);
            $expectedPeople = $people;
            sort($expectedPeople);
            $this->assertSame($expectedPeople, $records->pluck('person_id')->sort()->values()->all());
            $this->assertDatabaseHas('people', ['id' => $people[0], 'pseudonym' => 'FICTIF_ALPHA', 'organization_id' => $ctx->organizationId]);
            $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'FICTIF Alpha']);
            $this->assertDatabaseHas('matches', ['id' => $match->id, 'organization_id' => $ctx->organizationId]);
            $this->assertSame('FICTIF Match', $match->fresh()?->title);
            $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'reference' => 'fictif-player-01']);
            $this->assertSame(1, DB::table('teams')->where('organization_id', $ctx->organizationId)->count());
            $this->assertSame(1, DB::table('matches')->where('organization_id', $ctx->organizationId)->count());
            $this->assertSame(1, DB::table('contracts')->where('organization_id', $ctx->organizationId)->count());

            return ['people_created' => count($people), 'teams_created' => 1, 'matches_created' => 1, 'contracts_created' => 1,
                'observations_created' => $records->count(), 'samples_checked' => 4, 'links_checked' => 5];
        });
    }

    public function test_retry_resume_and_duplicate_receipts_preserve_business_totals(): void
    {
        $s = $this->baseline();
        $this->reversible('idempotency', function () use ($s): array {
            $service = app(PersonImportService::class);
            $processor = app(PersonImportProcessor::class);
            $ctx = $this->context($s);
            $run = $this->preparePeople($s);
            $run = $service->confirm($ctx, $run->id, ['version' => $run->version, 'confirm' => true]);
            $this->assertTrue($processor->process($ctx, $run->id));
            $processor->failed($ctx->organizationId, $run->id, $ctx->actorUserId);
            $run = $service->confirm($ctx, $run->id, ['version' => $run->confirmed_version, 'confirm' => true]);
            $this->assertFalse($processor->process($ctx, $run->id));
            $this->assertFalse($processor->process($ctx, $run->id));
            $this->assertSame($run->id, $service->upload($ctx, $this->peopleCsv(), ',')->id);
            $people = $this->people($s);
            $row = [...$s['input'], 'person_id' => $people[0], 'source_label' => 'FICTIF duplicate'];
            $first = $this->importStatistics($s, [$row]);
            $same = $this->importStatistics($s, [$row]);
            $duplicate = $this->importStatistics($s, [[...$row, 'reason' => 'FICTIF autre justification documentee.']]);
            $this->assertSame($first['id'], $same['id']);
            $this->assertSame(1, $duplicate['counts']['duplicate']);
            $this->assertSame(1, StatisticalObservation::query()->where('organization_id', $ctx->organizationId)->count());

            return ['people_created' => count($people), 'observations_created' => 1, 'duplicates_skipped' => $duplicate['counts']['duplicate']];
        });
    }

    public function test_invalid_rows_and_conflicts_do_not_overwrite_existing_values(): void
    {
        $s = $this->baseline();
        $this->reversible('invalid_rows', function () use ($s): array {
            $ctx = $this->context($s);
            $invalid = $this->preparePeople($s, "pseudonym,email\nFICTIF_VALID,valid@example.test\nFICTIF_INVALID,invalid-email\n");
            $this->assertSame(1, DB::table('person_import_rows')->where('import_id', $invalid->id)->where('status', 'invalid')->count());
            $this->refused(fn () => app(PersonImportService::class)->confirm($ctx, $invalid->id, ['version' => $invalid->version, 'confirm' => true]));
            $this->assertSame(0, Person::query()->where('organization_id', $ctx->organizationId)->where('pseudonym', 'FICTIF_VALID')->count());
            $row = [...$s['input'], 'source_label' => 'FICTIF conflict', 'value' => '0.000000'];
            $this->importStatistics($s, [$row]);
            $conflict = $this->importStatistics($s, [[...$row, 'value' => '2.500000']]);
            $this->assertSame(1, $conflict['counts']['rejected']);
            $this->assertSame('0', StatisticalObservation::query()->where('organization_id', $ctx->organizationId)->with('current')->firstOrFail()->current?->value);
            $preview = app(StatisticalImportService::class)->upload($ctx, $this->csv([[...$row, 'value' => 'invalid-number']]), ',');
            $this->assertSame(1, $preview->invalid_count);

            return ['invalid_rows' => 2, 'conflicts_rejected' => $conflict['counts']['rejected'], 'observations_created' => 1];
        });
    }

    public function test_foreign_tenant_references_and_actor_permissions_are_enforced(): void
    {
        $s = $this->baseline();
        $foreign = Person::factory()->create();
        $foreignBefore = $foreign->refresh()->getRawOriginal();
        $this->reversible('tenant_isolation', function () use ($s, $foreign, $foreignBefore): array {
            $ctx = $this->context($s);
            $this->refused(fn () => app(ContractService::class)->save($ctx, ['reference' => 'fictif-foreign', 'type' => 'player', 'title' => 'FICTIF foreign',
                'responsible_person_id' => $foreign->id, 'parties' => [['kind' => 'person', 'person_id' => $foreign->id, 'name' => 'FICTIF foreign']]]));
            $preview = app(StatisticalImportService::class)->upload($ctx, $this->csv([[...$s['input'], 'person_id' => $foreign->id]]), ',');
            $this->assertSame(0, $preview->valid_count);
            $this->assertSame(1, $preview->invalid_count);
            $this->refused(fn () => app(StatisticalImportConfirmation::class)->confirm($ctx, $preview->id, ['version' => $preview->id, 'confirm' => true]));
            $reader = $this->personContext($s['org'], $s['reader']);
            $this->refused(fn () => app(PersonImportService::class)->upload($reader, $this->peopleCsv(), ','));
            $this->assertSame(0, DB::table('contracts')->where('organization_id', $ctx->organizationId)->count());
            $this->assertSame(0, StatisticalObservation::query()->where('organization_id', $ctx->organizationId)->count());
            $this->assertDatabaseHas('people', ['id' => $foreign->id, 'organization_id' => $foreign->organization_id]);
            $this->assertSame($foreignBefore, $foreign->fresh()?->getRawOriginal());

            return ['denied_requests' => 3, 'out_of_tenant_writes' => 0];
        });
    }

    public function test_interruption_after_first_processed_row_restores_the_full_baseline(): void
    {
        $s = $this->baseline();
        $failure = new RuntimeException('FICTIF controlled interruption.');
        $this->reversible('interruption', function () use ($s, $failure): never {
            $ctx = $this->context($s);
            $run = $this->preparePeople($s);
            app(PersonImportService::class)->confirm($ctx, $run->id, ['version' => $run->version, 'confirm' => true]);
            $this->assertTrue(app(PersonImportProcessor::class)->process($ctx, $run->id));
            $partial = Person::query()->where('organization_id', $ctx->organizationId)->whereIn('pseudonym', ['FICTIF_ALPHA', 'FICTIF_BETA'])->count();
            $this->assertSame(1, $partial);
            throw $failure;
        }, $failure);
    }

    /** @return array<string,mixed> */
    private function baseline(): array
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        $s = $this->observationScenario();
        $role = $s['assignment']->role;
        $role->forceFill(['permissions' => [...$role->permissions, 'sports.statistics.import', 'teams.manage', 'administrative.read', 'administrative.write']])->save();

        return $s;
    }

    /** @param array<string,mixed> $s */
    private function context(array $s): TenantContext
    {
        return $this->personContext($s['org'], $s['writer']);
    }

    private function peopleCsv(): string
    {
        return "pseudonym,email\nFICTIF_ALPHA,alpha@example.test\nFICTIF_BETA,beta@example.test\n";
    }

    /** @param array<string,mixed> $s */
    private function preparePeople(array $s, ?string $contents = null): PersonImport
    {
        $service = app(PersonImportService::class);
        $ctx = $this->context($s);
        $run = $service->upload($ctx, $contents ?? $this->peopleCsv(), ',');

        return $service->preview($ctx, $run->id, ['version' => $run->version, 'mapping' => ['pseudonym' => 0, 'contact_email' => 1],
            'defaults' => ['language' => 'fr', 'timezone' => 'UTC', 'status' => 'active']]);
    }

    /** @param array<string,mixed> $s
     * @return list<string> */
    private function importPeople(array $s): array
    {
        $run = $this->preparePeople($s);
        $ctx = $this->context($s);
        app(PersonImportService::class)->confirm($ctx, $run->id, ['version' => $run->version, 'confirm' => true]);
        for ($step = 0; $step < 4; $step++) {
            if (! app(PersonImportProcessor::class)->process($ctx, $run->id)) {
                break;
            }
        }
        $people = $this->people($s);
        $this->assertCount(2, $people);

        return $people;
    }

    /** @param array<string,mixed> $s
     * @return list<string> */
    private function people(array $s): array
    {
        return array_values(Person::query()->where('organization_id', $s['org']->id)->whereIn('pseudonym', ['FICTIF_ALPHA', 'FICTIF_BETA'])->orderBy('pseudonym')->get()->map(fn (Person $person): string => $person->id)->all());
    }

    /** @param array<string,mixed> $s
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed> */
    private function importStatistics(array $s, array $rows): array
    {
        $ctx = $this->context($s);
        $service = app(StatisticalImportService::class);
        $run = $service->upload($ctx, $this->csv($rows), ',');
        app(StatisticalImportConfirmation::class)->confirm($ctx, $run->id, ['version' => $run->id, 'confirm' => true, 'acknowledge_rejected' => true]);
        $execution = StatisticalImportExecution::query()->where('import_id', $run->id)->firstOrFail();
        for ($step = 0; $step < 20; $step++) {
            if (! app(StatisticalImportProcessor::class)->process($ctx, $run->id, $execution->version)) {
                break;
            }
        }

        return $service->show($ctx, $run->id);
    }

    /** @param list<array<string,mixed>> $rows */
    private function csv(array $rows): string
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fputcsv($stream, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), escape: '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function refused(Closure $action): void
    {
        try {
            $action();
            $this->fail('The forbidden operation must be rejected.');
        } catch (AuthorizationException|ModelNotFoundException|ValidationException|DomainConflictException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string,string> */
    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $rows = DB::table($table)->get()->map(static fn (object $row): string => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $result[$table] = hash('sha256', implode("\n", $rows));
        }
        ksort($result);

        return $result;
    }

    /** @param Closure():array<string,int|bool> $scenario */
    private function reversible(string $name, Closure $scenario, ?RuntimeException $expectedFailure = null): void
    {
        $before = $this->snapshot();
        $files = Storage::disk('data002')->allFiles();
        $level = DB::transactionLevel();
        $this->assertSame(0, $level);
        DB::beginTransaction();
        try {
            $facts = $scenario();
            if ($expectedFailure !== null) {
                $this->fail('The interruption must escape the import workflow.');
            }
        } catch (RuntimeException $exception) {
            if ($exception !== $expectedFailure) {
                throw $exception;
            }
            $facts = ['partial_rows' => 1, 'interruption_detected' => true];
        } finally {
            DB::rollBack($level);
            $created = array_values(array_diff(Storage::disk('data002')->allFiles(), $files));
            $root = realpath(Storage::disk('data002')->path(''));
            $allowed = realpath(storage_path('framework/testing/disks'));
            if ($root === false || $allowed === false || ! str_starts_with($root, $allowed.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Unsafe rehearsal artifact directory.');
            }
            Storage::disk('data002')->delete($created);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($files, Storage::disk('data002')->allFiles());
        $this->assertInstanceOf(QueueFake::class, Queue::getFacadeRoot());
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $path = getenv('UCG_DATA_REHEARSAL_RESULTS');
        $parent = is_string($path) ? realpath(dirname($path)) : false;
        $allowed = realpath(storage_path('framework/testing/data-rehearsal'));
        if (! is_string($path) || $parent === false || $allowed === false || ! str_starts_with($parent, $allowed.DIRECTORY_SEPARATOR) || is_link($path)) {
            throw new RuntimeException('Unsafe rehearsal evidence directory.');
        }
        $proof = ['check' => $name, 'facts' => [...$facts, 'baseline_restored' => true, 'artifacts_restored' => true]];
        if (file_put_contents($path, json_encode($proof, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Cannot record rehearsal evidence.');
        }
    }
}
