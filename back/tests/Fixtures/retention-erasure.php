<?php

use App\Exceptions\DomainException;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\ErasureAccessGate;
use App\Modules\Tenancy\Application\Services\ErasureReconciliationService;
use App\Modules\Tenancy\Application\Services\ManualErasureService;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabasePortabilityRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Native services only, booted exclusively by the ownership-checked recovery harness.
if (getenv('UCG_RECOVERY_FIXTURE') !== 'v1' || getenv('UCG_BROWSER_ENGINE') !== 'pgsql'
    || ! in_array($argv[1] ?? '', ['prepare', 'plan', 'begin', 'inspect', 'drain', 'acknowledge', 'hold', 'release-hold',
        'crash-after-file', 'crash-after-row', 'reconcile', 'access', 'snapshot', 'schema', 'changed-generation', 'beta-change'], true)) {
    throw new RuntimeException('Owned erasure fixture required.');
}
try {
    /** @var Application $app */
    $app = require __DIR__.'/browser-application.php';
    $app->make(Kernel::class)->bootstrap();
    $root = (string) getenv('UCG_BROWSER_ROOT');
    $journalRoot = getenv('UCG_ERASURE_JOURNAL_ROOT');
    $journalToken = getenv('UCG_ERASURE_JOURNAL_TOKEN');
    if (! is_string($journalRoot) || realpath($journalRoot) !== $journalRoot || is_link($journalRoot)
        || realpath(dirname($journalRoot)) !== realpath(sys_get_temp_dir())
        || preg_match('/^ucg-browser-[a-zA-Z0-9]+$/D', basename($journalRoot)) !== 1
        || ! is_string($journalToken) || preg_match('/^[a-f0-9]{64}$/D', $journalToken) !== 1
        || ! is_file($journalRoot.'/owner') || ! hash_equals($journalToken, trim((string) file_get_contents($journalRoot.'/owner')))) {
        throw new RuntimeException('ERASURE_FIXTURE_OWNERSHIP');
    }
    $journalPath = $journalRoot.'/erasure-journal.sqlite';
    $operation = $argv[1];
    if ($operation === 'prepare' && ! is_file($journalPath) && $journalRoot === $root) {
        if (file_put_contents($journalPath, '', LOCK_EX) === false) {
            throw new RuntimeException('ERASURE_FIXTURE_JOURNAL');
        }
    }
    if (! is_file($journalPath) || is_link($journalPath) || realpath($journalPath) !== $journalRoot.DIRECTORY_SEPARATOR.'erasure-journal.sqlite') {
        throw new RuntimeException('ERASURE_FIXTURE_JOURNAL');
    }
    $bytes = stream_get_contents(STDIN, 65537);
    $input = $bytes === '' ? [] : json_decode((string) $bytes, true, 32, JSON_THROW_ON_ERROR);
    if (! is_string($bytes) || strlen($bytes) > 65536 || ! is_array($input)) {
        throw new RuntimeException('ERASURE_FIXTURE_INPUT');
    }
    $db = $app->make(DatabaseManager::class)->connection();
    $org = isset($input['organization']) ? Organization::query()->where('id', $input['organization'])->firstOrFail()
        : Organization::query()->where('slug', 'recovery-alpha')->sole();
    $beta = Organization::query()->where('slug', 'recovery-beta')->sole();
    $epoch = getenv('UCG_ERASURE_RESTORE_EPOCH');
    config(['database.connections.erasure_lab' => ['driver' => 'sqlite', 'database' => $journalPath, 'foreign_key_constraints' => true],
        'retention.erasure.journal_connection' => 'erasure_lab', 'retention.erasure.journal_independence_reviewed' => true,
        'retention.erasure.installation_id' => getenv('UCG_ERASURE_INSTALLATION'), 'retention.erasure.journal_key' => getenv('UCG_ERASURE_KEY'),
        'retention.erasure.enabled' => getenv('UCG_ERASURE_DISABLED') !== 'true', 'retention.control_enabled' => true,
        'retention.control_operators' => [$org->owner_user_id => [$org->id]], 'retention.erasure.operators' => [$org->owner_user_id => [$org->id]],
        'retention.erasure.restore_mode' => is_string($epoch) && $epoch !== '', 'retention.erasure.restore_epoch' => $epoch,
        'tenancy.cache_store' => 'array', 'filesystems.artifacts' => 'local']);
    $audit = new OrganizationAuditData($input['actor'] ?? $org->owner_user_id, 'Fictitious OPS-007 qualification', (string) Str::uuid7());

    if ($operation === 'crash-after-file') {
        $disk = $app->make(Factory::class)->disk('local');
        $interrupted = Mockery::mock(Filesystem::class);
        $interrupted->shouldReceive('exists')->andReturnUsing(fn ($path) => $disk->exists($path));
        $interrupted->shouldReceive('delete')->andReturnUsing(function ($path) use ($disk): never {
            if (! $disk->delete($path)) {
                throw new RuntimeException('ERASURE_FIXTURE_DELETE');
            }
            exit(73); // I/O survived, while the source transaction must roll back.
        });
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('disk')->with('local')->andReturn($interrupted);
        $app->instance(Factory::class, $factory);
    }
    if ($operation === 'crash-after-row') {
        $db->listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'delete from "contract_document_versions"')) {
                exit(74); // A protected business-row delete cannot commit its partial batch.
            }
        });
    }
    $service = $app->make(ManualErasureService::class);
    if ($operation === 'changed-generation') {
        $db->transaction(function () use ($db, $app, $org, $audit, $input): never {
            $db->unprepared('ALTER TABLE organization_access_events ALTER COLUMN membership_id DROP EXPRESSION');
            $app->make(ErasureReconciliationService::class)->run($org->id, $audit, $input['head'], $input['confirmation']);
            throw new RuntimeException('ERASURE_FIXTURE_CHANGED_SCHEMA_ACCEPTED');
        });
    }
    $result = match ($operation) {
        'plan' => $service->plan($org->id, $audit, 'organization', $org->id)->data,
        'begin' => $service->begin($org->id, $audit, 'organization', $org->id, $input['digest'], 'fixture-erasure', $input['confirmation'])->data,
        'inspect' => $service->inspect($org->id, $audit, $input['operation'])->data,
        'reconcile' => $app->make(ErasureReconciliationService::class)->run($org->id, $audit, $input['head'] ?? null, $input['confirmation'] ?? null)->data,
        default => null,
    };
    if ($operation === 'prepare') {
        $service->initializeJournal($org->id, $audit);
        $app->make(OrganizationService::class)->transition($org->id, OrganizationStatus::Closing, $audit);
        $app->make(OrganizationService::class)->transition($org->id, OrganizationStatus::Archived, $audit);
        Queue::fake();
        $exports = $app->make(OrganizationPortabilityService::class);
        $export = $exports->requestExport($org->id, $audit);
        $exports->generate($org->id, $export->id);
        $export = $app->make(PortabilityRepository::class)->find($org->id, $export->id);
        $stream = $exports->read($org->id, $export->id, $audit);
        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if (! is_string($contents) || $export->sha256 !== hash('sha256', $contents)
            || str_contains($contents, $beta->id) || ! str_contains($contents, '"table":"contract_transitions"')
            || ! str_contains($contents, '"table":"match_result_events"')) {
            throw new RuntimeException('ERASURE_FIXTURE_EXPORT');
        }
        $result = ['organization' => $org->id, 'beta' => $beta->id, 'export' => $export->id, 'export_rows' => $export->details['rows'],
            'export_files' => $export->details['files'], 'export_sha256' => $export->sha256];
    }
    if (in_array($operation, ['drain', 'crash-after-file', 'crash-after-row'], true)) {
        for ($batch = 0; $batch < 300; $batch++) {
            $result = $service->resume($org->id, $audit, $input['operation'])->data;
            if ($result['operation']['status'] !== 'erasing') {
                break;
            }
        }
        if (($result['operation']['status'] ?? null) === 'erasing' || $operation !== 'drain') {
            throw new RuntimeException('ERASURE_FIXTURE_BOUND');
        }
    }
    if ($operation === 'acknowledge') {
        foreach (RetentionCatalog::UNVERIFIED_COPIES as $category) {
            $result = $service->acknowledge($org->id, $audit, $input['operation'], $category, 'synthetic-review-'.$category)->data;
        }
    }
    if (in_array($operation, ['hold', 'release-hold'], true)) {
        $state = $app->make(RetentionControlRepository::class)->state($org->id);
        $result = $app->make(RetentionControlService::class)->run($org->id, $audit, $operation, $state['version'], 'fixture-preservation', $org->id)->data;
    }
    if ($operation === 'access') {
        $app->make(ErasureAccessGate::class)->assertReadable($org->id);
        $result = ['readable' => true];
    }
    if ($operation === 'beta-change') {
        Person::factory()->create(['organization_id' => $beta->id, 'pseudonym' => 'Fictitious Beta during erasure']);
        $result = ['changed' => true];
    }
    if ($operation === 'schema') {
        $result = [];
        foreach (array_keys(DatabasePortabilityRepository::TABLES) as $table) {
            $result[$table] = [array_column($db->getSchemaBuilder()->getColumns($table), null, 'name'), $db->getSchemaBuilder()->getForeignKeys($table)];
        }
    }
    if ($operation === 'snapshot') {
        $catalog = $app->make(DatabasePortabilityRepository::class);
        $catalog->assertCatalog();
        $result = ['alpha' => [], 'beta' => [], 'global' => [], 'quarantine' => $app->make(RetentionControlRepository::class)->state($org->id)['quarantine'],
            'journal_head' => $app->make(ErasureJournal::class)->head($org->id)];
        foreach ($db->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $entry) {
            $table = $entry->tablename;
            if (! is_string($table) || preg_match('/^[a-z_]+$/D', $table) !== 1) {
                throw new RuntimeException('ERASURE_FIXTURE_CATALOG');
            }
            $owned = array_key_exists($table, DatabasePortabilityRepository::TABLES);
            foreach ($owned ? ['alpha' => $org->id, 'beta' => $beta->id] : ['global' => null] as $scope => $id) {
                $query = $db->table($table);
                if ($id !== null) {
                    $query->where($table === 'organizations' ? 'id' : 'organization_id', $id);
                }
                $rows = $query->limit(100001)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
                if (count($rows) > 100000) {
                    throw new RuntimeException('ERASURE_FIXTURE_BOUND');
                }
                sort($rows, SORT_STRING);
                $result[$scope][$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
            }
        }
    }
    if ((int) $db->scalar("SELECT COUNT(*) FROM pg_class WHERE relnamespace = pg_my_temp_schema() AND relname LIKE 'ucg_erasure_schema_%'") !== 0) {
        throw new RuntimeException('ERASURE_FIXTURE_TEMPORARY_VIEW_LEAK');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (isset($root) && is_dir($root.'/storage/logs')) {
        file_put_contents($root.'/storage/logs/erasure-fixture.log', (string) $exception, FILE_APPEND | LOCK_EX);
    }
    echo json_encode(['refused' => $exception instanceof DomainException ? $exception->errorCode() : 'ERASURE_FIXTURE_FAILED'], JSON_THROW_ON_ERROR);
    exit(1);
}
