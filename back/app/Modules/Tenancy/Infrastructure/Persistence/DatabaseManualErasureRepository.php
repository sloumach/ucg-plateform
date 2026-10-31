<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\ManualErasureRepository;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\ErasureManifest;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Modules\Tenancy\Infrastructure\Resources\ManualErasureFiles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use LogicException;

/** @phpstan-import-type ErasureManifest from ManualErasureRepository as ErasureManifestData
 * @phpstan-import-type ErasurePlan from ManualErasureRepository
 * @phpstan-import-type ErasureOperation from ManualErasureRepository
 */
final readonly class DatabaseManualErasureRepository implements ManualErasureRepository
{
    private const array PROTECTED = ['organizations', 'organization_retention_events', 'tenant_portability_operations', 'tenant_storage_usage', ...RetentionCatalog::ERASURE_CONTROL_TABLES];

    private const array OWNED = ['people', 'person_game_profiles', 'person_external_accounts', 'person_periods', 'person_departure_items', 'person_departures', 'person_import_rows', 'person_imports', 'tenant_artifacts'];

    public function __construct(private Connection $database, private PortabilityRepository $catalog,
        private ScopedErasurePermit $permits, private ManualErasureFiles $files, private Repository $config) {}

    public function plan(string $organizationId, string $scope, string $targetId): array
    {
        $this->catalog->assertCatalog();
        $tables = $scope === 'organization' ? array_values(array_diff(array_keys(DatabasePortabilityRepository::TABLES), self::PROTECTED)) : self::OWNED;
        [$order, $detach, $schemaDigest] = $this->graph($tables);
        $imports = $scope === 'person' ? array_values($this->database->table('person_import_rows')->where('organization_id', $organizationId)
            ->where('person_id', $targetId)->distinct()->limit(100001)->pluck('import_id')->map(static fn ($id): string => (string) $id)->all()) : [];
        $manifest = ['files' => [], 'imports' => $imports, 'order' => $order, 'detach' => $detach];
        $blockers = [];
        if ($scope === 'person') {
            $person = $this->database->table('people')->where('organization_id', $organizationId)->where('id', $targetId)->first();
            if ($person === null || $person->deleted_at === null || $person->status !== 'former') {
                $blockers[] = 'person_not_soft_deleted';
            }
            if (! $this->database->table('person_departures')->where('organization_id', $organizationId)->where('person_id', $targetId)->exists()
                || $this->database->table('person_departure_items')->where('organization_id', $organizationId)->where('person_id', $targetId)->where('status', 'open')->exists()) {
                $blockers[] = 'departure_or_obligations_unresolved';
            }
        }
        foreach ($this->query('tenant_artifacts', $organizationId, $scope, $targetId, $manifest)->limit(100001)->get(['id', 'disk']) as $file) {
            $manifest['files'][] = ['id' => (string) $file->id, 'disk' => (string) $file->disk, 'kind' => 'artifact'];
        }
        foreach ($this->database->table('tenant_portability_operations')->where('organization_id', $organizationId)->where('kind', 'export')
            ->whereNotNull('disk')->limit(100001)->get(['id', 'disk']) as $file) {
            $manifest['files'][] = ['id' => (string) $file->id, 'disk' => (string) $file->disk, 'kind' => 'export'];
        }
        $manifest = ErasureManifest::parse($manifest);
        if ($scope === 'person') {
            $blockers = [...$blockers, ...$this->sharedReferences($organizationId, $targetId, $manifest)];
        }
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $this->query($table, $organizationId, $scope, $targetId, $manifest)->count();
        }
        $used = (int) $this->database->table('tenant_storage_usage')->where('organization_id', $organizationId)->value('bytes');
        if ($used !== (int) $this->database->table('tenant_artifacts')->where('organization_id', $organizationId)->sum('bytes')) {
            $blockers[] = 'storage_accounting_inconsistent';
        }
        $hash = hash_init('sha256', HASH_HMAC, (string) $this->config->get('app.key'));
        hash_update($hash, ErasureManifest::canonical(['organization_id' => $organizationId, 'scope' => $scope, 'target_id' => $targetId, 'manifest' => $manifest]));
        $started = hrtime(true);
        foreach ($this->catalog->rows($organizationId, 100000) as $row) {
            hash_update($hash, ErasureManifest::canonical($row)."\n");
            if (hrtime(true) - $started > 45_000_000_000) {
                throw new DomainConflictException(__('retention.erasure_limit'), 'ERASURE_LIMIT');
            }
        }

        return ['organization_id' => $organizationId, 'scope' => $scope, 'target_id' => $targetId,
            'source_digest' => hash_final($hash), 'schema_digest' => $schemaDigest, 'counts' => $counts,
            'blockers' => array_values(array_unique($blockers)), 'manifest' => $manifest];
    }

    public function create(array $plan, int $actorId, string $requestId, string $reference, ?string $sourceIntentId = null, ?string $restoreEpoch = null): array
    {
        $id = (string) Str::uuid7();
        $record = ['id' => $id, 'organization_id' => $plan['organization_id'], 'actor_user_id' => $actorId,
            'request_id' => $requestId, 'scope' => $plan['scope'], 'target_id' => $plan['target_id'], 'reference' => $reference,
            'source_digest' => $plan['source_digest'], 'schema_digest' => $plan['schema_digest'], 'manifest' => json_encode($plan['manifest'], JSON_THROW_ON_ERROR),
            'source_intent_id' => $sourceIntentId, 'restore_epoch' => $restoreEpoch, 'status' => 'erasing',
            'progress' => json_encode(['deleted' => 0, 'files_cursor' => 0, 'detached' => false], JSON_THROW_ON_ERROR),
            'copy_receipts' => '{}', 'version' => 1, 'created_at' => now('UTC')->startOfSecond(), 'updated_at' => now('UTC')->startOfSecond()];
        $this->database->table('tenant_erasure_operations')->insert($record);
        $this->event($record['organization_id'], $id, 1, $actorId, $requestId, 'approved', ['source_digest' => $plan['source_digest'], 'counts' => $plan['counts']]);

        return $this->find($record['organization_id'], $id);
    }

    public function find(string $organizationId, string $operationId): array
    {
        $row = $this->database->table('tenant_erasure_operations')->where('organization_id', $organizationId)->where('id', $operationId)->firstOrFail();
        $progress = json_decode((string) $row->progress, true, 16, JSON_THROW_ON_ERROR);
        $receipts = json_decode((string) $row->copy_receipts, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($progress) || count($progress) !== 3 || ! is_int($progress['deleted'] ?? null) || $progress['deleted'] < 0
            || ! is_int($progress['files_cursor'] ?? null) || $progress['files_cursor'] < 0 || ! is_bool($progress['detached'] ?? null) || ! is_array($receipts)) {
            throw new LogicException('Invalid erasure progress.');
        }
        $copies = [];
        foreach ($receipts as $category => $reference) {
            if (! is_string($category) || ! in_array($category, RetentionCatalog::UNVERIFIED_COPIES, true) || ! is_string($reference)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $reference) !== 1) {
                throw new LogicException('Invalid erasure copy receipt.');
            }
            $copies[$category] = $reference;
        }

        return ['id' => (string) $row->id, 'organization_id' => (string) $row->organization_id, 'actor_user_id' => (int) $row->actor_user_id,
            'request_id' => (string) $row->request_id, 'scope' => (string) $row->scope, 'target_id' => (string) $row->target_id,
            'reference' => (string) $row->reference, 'source_digest' => (string) $row->source_digest, 'schema_digest' => (string) $row->schema_digest,
            'status' => (string) $row->status, 'manifest' => ErasureManifest::parse(json_decode((string) $row->manifest, true, 64, JSON_THROW_ON_ERROR)),
            'source_intent_id' => $row->source_intent_id === null ? null : (string) $row->source_intent_id,
            'restore_epoch' => $row->restore_epoch === null ? null : (string) $row->restore_epoch,
            'progress' => ['deleted' => $progress['deleted'], 'files_cursor' => $progress['files_cursor'], 'detached' => $progress['detached']],
            'copy_receipts' => $copies, 'version' => (int) $row->version, 'created_at' => CarbonImmutable::parse($row->created_at)->utc()->toIso8601String()];
    }

    public function replay(string $organizationId, string $intentId, string $restoreEpoch): ?array
    {
        $id = $this->database->table('tenant_erasure_operations')->where('organization_id', $organizationId)
            ->where('source_intent_id', $intentId)->where('restore_epoch', $restoreEpoch)->value('id');

        return $id === null ? null : $this->find($organizationId, (string) $id);
    }

    public function completedIntent(string $organizationId, string $intentId): ?array
    {
        $id = $this->database->table('tenant_erasure_operations')->where('organization_id', $organizationId)->where('status', 'completed')
            ->where(fn (Builder $query) => $query->where('id', $intentId)->orWhere('source_intent_id', $intentId))
            ->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return $id === null ? null : $this->find($organizationId, (string) $id);
    }

    public function batch(array $operation, int $actorId, string $requestId): array
    {
        if ($operation['status'] !== 'erasing') {
            return $operation;
        }
        $progress = $operation['progress'];
        $manifest = $operation['manifest'];
        $organizationId = $operation['organization_id'];
        if ($progress['files_cursor'] < count($manifest['files'])) {
            foreach (array_slice($manifest['files'], $progress['files_cursor'], 100) as $file) {
                $this->files->erase($organizationId, $operation['id'], $file);
                if ($file['kind'] === 'export') {
                    $this->catalog->update($organizationId, $file['id'], ['status' => 'expired', 'bytes' => 0]);
                }
                $progress['files_cursor']++;
            }

            return $this->save($operation, 'erasing', $progress, $operation['copy_receipts'], $actorId, $requestId, 'files_removed');
        }
        if (! $progress['detached']) {
            foreach ($manifest['detach'] as $table => $columns) {
                $query = $this->query($table, $organizationId, $operation['scope'], $operation['target_id'], $manifest)
                    ->where(function (Builder $query) use ($columns): void {
                        foreach ($columns as $column) {
                            $query->orWhereNotNull($column);
                        }
                    });
                $key = DatabasePortabilityRepository::TABLES[$table];
                $rows = $query->orderBy($key)->limit(100)->get([$key]);
                if ($rows->isNotEmpty()) {
                    foreach ($rows as $row) {
                        $value = (string) $row->{$key};
                        $expected = array_fill_keys($columns, null);
                        $this->permits->run($organizationId, $operation['id'], $table, $key, $value, $expected,
                            fn () => $this->database->table($table)->where('organization_id', $organizationId)->where($key, $value)->update($expected));
                    }

                    return $this->save($operation, 'erasing', $progress, $operation['copy_receipts'], $actorId, $requestId, 'dependencies_detached');
                }
            }
            $progress['detached'] = true;
        }
        foreach ($manifest['order'] as $table) {
            $key = DatabasePortabilityRepository::TABLES[$table];
            $query = $this->query($table, $organizationId, $operation['scope'], $operation['target_id'], $manifest);
            foreach ($this->database->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] !== $table) {
                    continue;
                }
                $query->whereNotExists(function (Builder $children) use ($table, $key, $foreignKey): void {
                    $children->selectRaw('1')->from($table.' as erasure_child')->whereColumn('erasure_child.'.$key, '<>', $table.'.'.$key);
                    foreach ($foreignKey['columns'] as $index => $column) {
                        $children->whereColumn('erasure_child.'.$column, $table.'.'.$foreignKey['foreign_columns'][$index]);
                    }
                });
            }
            $rows = $query->orderBy($key)->limit(100)->get();
            if ($rows->isEmpty()) {
                if ($this->query($table, $organizationId, $operation['scope'], $operation['target_id'], $manifest)->exists()) {
                    throw new DomainConflictException(__('retention.erasure_graph_blocked'), 'ERASURE_GRAPH_BLOCKED');
                }

                continue;
            }
            foreach ($rows as $row) {
                $value = (string) $row->{$key};
                if ($table === 'tenant_artifacts') {
                    $remaining = (int) $this->database->table('tenant_storage_usage')->where('organization_id', $organizationId)->value('bytes') - (int) $row->bytes;
                    if ($remaining < 0) {
                        throw new LogicException('Erasure accounting is inconsistent.');
                    }
                    $this->permits->run($organizationId, $operation['id'], 'tenant_storage_usage', 'organization_id', $organizationId, ['bytes' => $remaining],
                        fn () => $this->database->table('tenant_storage_usage')->where('organization_id', $organizationId)->update(['bytes' => $remaining]));
                }
                $this->permits->run($organizationId, $operation['id'], $table, $key, $value, null,
                    fn () => $this->database->table($table)->where('organization_id', $organizationId)->where($key, $value)->delete());
                $progress['deleted']++;
            }
            if ($operation['scope'] === 'person') {
                foreach ($this->database->table('dashboard_source_versions')->where('organization_id', $organizationId)->get() as $projection) {
                    $expected = ['generation' => (int) $projection->generation + 1];
                    $this->permits->run($organizationId, $operation['id'], 'dashboard_source_versions', 'card', (string) $projection->card, $expected,
                        fn () => $this->database->table('dashboard_source_versions')->where('organization_id', $organizationId)->where('card', $projection->card)->update($expected));
                }
            }

            return $this->save($operation, 'erasing', $progress, $operation['copy_receipts'], $actorId, $requestId, 'rows_removed');
        }
        if ($operation['scope'] === 'organization') {
            $expected = ['name' => '[Erased organization]', 'slug' => 'erased-'.$organizationId, 'country' => 'ZZ', 'timezone' => 'UTC', 'language' => 'fr'];
            $this->permits->run($organizationId, $operation['id'], 'organizations', 'id', $organizationId, $expected,
                fn () => $this->database->table('organizations')->where('id', $organizationId)->update($expected));
        }

        return $this->save($operation, 'awaiting_copies', $progress, $operation['copy_receipts'], $actorId, $requestId, 'local_erasure_complete');
    }

    public function acknowledge(array $operation, string $category, string $reference, int $actorId, string $requestId): array
    {
        if ($operation['status'] !== 'awaiting_copies' || ! in_array($category, RetentionCatalog::UNVERIFIED_COPIES, true)) {
            throw new DomainConflictException(__('retention.copies_pending'), 'ERASURE_COPIES_PENDING');
        }
        $receipts = $operation['copy_receipts'];
        if (isset($receipts[$category]) && $receipts[$category] !== $reference) {
            throw new DomainConflictException(__('retention.request_conflict'), 'ERASURE_RECEIPT_CONFLICT');
        }
        $receipts[$category] = $reference;
        $complete = count($receipts) === count(RetentionCatalog::UNVERIFIED_COPIES);

        return $this->save($operation, $complete ? 'completed' : 'awaiting_copies', $operation['progress'], $receipts, $actorId, $requestId, 'copy_review_recorded');
    }

    public function assertExecutable(array $operation): void
    {
        $this->catalog->assertCatalog();
        $tables = $operation['scope'] === 'organization'
            ? array_values(array_diff(array_keys(DatabasePortabilityRepository::TABLES), self::PROTECTED)) : self::OWNED;
        [$order, $detach, $digest] = $this->graph($tables);
        if (! hash_equals($operation['schema_digest'], $digest) || $order !== $operation['manifest']['order'] || $detach !== $operation['manifest']['detach']) {
            throw new DomainConflictException(__('retention.erasure_guards_changed'), 'ERASURE_GUARDS_CHANGED');
        }
    }

    public function assertEmpty(array $operation): void
    {
        $this->assertExecutable($operation);
        foreach ($operation['manifest']['order'] as $table) {
            if ($this->query($table, $operation['organization_id'], $operation['scope'], $operation['target_id'], $operation['manifest'])->exists()) {
                throw new DomainConflictException(__('retention.erasure_residue'), 'ERASURE_RESIDUE');
            }
        }
        foreach ($operation['manifest']['files'] as $file) {
            if ($this->files->exists($operation['organization_id'], $file)) {
                throw new DomainConflictException(__('retention.erasure_residue'), 'ERASURE_RESIDUE');
            }
        }
    }

    public function guardHealth(): array
    {
        $health = [];
        $definitions = [];
        if ($this->database->getDriverName() === 'pgsql') {
            foreach ($this->database->select('SELECT n.nspname || \'.\' || p.proname || \'(\' || oidvectortypes(p.proargtypes) || \')\' AS identity, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.prokind = \'f\'') as $function) {
                $definitions[(string) $function->identity] = (string) $function->definition;
            }
            foreach ($this->database->select('SELECT c.relname || \'.\' || t.tgname AS identity, pg_get_triggerdef(t.oid) AS definition, t.tgenabled FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND NOT t.tgisinternal') as $trigger) {
                if (in_array($trigger->tgenabled, ['O', 'A'], true)) {
                    $definitions[(string) $trigger->identity] = (string) $trigger->definition;
                }
            }
        } else {
            foreach ($this->database->table('sqlite_master')->where('type', 'trigger')->get(['name', 'sql']) as $trigger) {
                $definitions[(string) $trigger->name] = (string) $trigger->sql;
            }
        }
        foreach ($this->database->table('retention_guard_definitions')->orderBy('id')->get() as $record) {
            $current = $definitions[(string) $record->id] ?? null;
            $health[(string) $record->id] = is_string($current) && hash_equals((string) $record->wrapped_hash, hash('sha256', $current));
        }
        if ($health === [] || $this->database->table('tenant_erasure_permits')->exists()) {
            $health['permit_or_registry_integrity'] = false;
        }

        return $health;
    }

    /** @param ErasureManifestData $manifest */
    private function query(string $table, string $org, string $scope, string $person, array $manifest): Builder
    {
        $query = $this->database->table($table)->where('organization_id', $org);
        if ($scope === 'organization') {
            return $query;
        }

        return match ($table) {
            'people' => $query->where('id', $person),
            'person_external_accounts' => $query->whereIn('game_profile_id', $this->database->table('person_game_profiles')->where('organization_id', $org)->where('person_id', $person)->select('id')),
            'person_imports' => $query->whereIn('id', $manifest['imports']),
            'person_import_rows' => $query->whereIn('import_id', $manifest['imports']),
            'tenant_artifacts' => $manifest['files'] === []
                ? $query->whereIn('id', $this->database->table('person_imports')->where('organization_id', $org)->whereIn('id', $manifest['imports'])->select('artifact_id'))
                : $query->whereIn('id', array_column(array_values(array_filter($manifest['files'], static fn ($file): bool => $file['kind'] === 'artifact')), 'id')),
            default => $query->where('person_id', $person),
        };
    }

    /** @param list<string> $tables
     * @return array{list<string>, array<string, list<string>>, string}
     */
    private function graph(array $tables): array
    {
        $schema = $this->database->getSchemaBuilder();
        $edges = [];
        $nullable = [];
        $metadata = [];
        foreach ($tables as $table) {
            $columns = array_column($schema->getColumns($table), null, 'name');
            if ($this->database->getDriverName() === 'pgsql') {
                $columns = $this->canonicalGeneratedColumns($table, $columns);
            }
            $foreignKeys = $schema->getForeignKeys($table);
            usort($foreignKeys, static fn (array $left, array $right): int => strcmp(ErasureManifest::canonical($left), ErasureManifest::canonical($right)));
            $metadata[$table] = [$columns, $foreignKeys];
            $edges[$table] = [];
            foreach ($foreignKeys as $foreignKey) {
                $parent = $foreignKey['foreign_table'];
                if (! in_array($parent, $tables, true) || $parent === $table) {
                    continue;
                }
                $fields = array_values(array_diff($foreignKey['columns'], ['organization_id']));
                $edges[$table][$parent] = true;
                $nullableFields = array_values(array_filter($fields, fn ($field): bool => $columns[$field]['nullable']));
                if ($nullableFields !== []) {
                    $nullable[$table][$parent] = $nullableFields;
                }
            }
        }
        $remaining = array_fill_keys($tables, true);
        $order = [];
        $detach = [];
        while ($remaining !== []) {
            $parents = [];
            foreach ($edges as $child => $references) {
                if (isset($remaining[$child])) {
                    foreach (array_keys($references) as $parent) {
                        if (isset($remaining[$parent])) {
                            $parents[$parent] = true;
                        }
                    }
                }
            }
            $leaves = array_diff_key($remaining, $parents);
            if ($leaves !== []) {
                $names = array_keys($leaves);
                sort($names);
                foreach ($names as $table) {
                    $order[] = $table;
                    unset($remaining[$table]);
                }

                continue;
            }
            $removed = false;
            foreach ($nullable as $child => $references) {
                if (! isset($remaining[$child])) {
                    continue;
                }
                foreach ($references as $parent => $fields) {
                    if (isset($remaining[$parent], $edges[$child][$parent])) {
                        unset($edges[$child][$parent]);
                        $detach[$child] = array_values(array_unique([...($detach[$child] ?? []), ...$fields]));
                        $removed = true;
                        break 2;
                    }
                }
            }
            if (! $removed) {
                throw new DomainConflictException(__('retention.erasure_graph_blocked'), 'ERASURE_GRAPH_BLOCKED');
            }
        }
        ksort($metadata);

        return [$order, $detach, hash('sha256', ErasureManifest::canonical(['driver' => $this->database->getDriverName(), 'tables' => $metadata]))];
    }

    /**
     * PostgreSQL can reparse equivalent generated expressions differently during pg_restore.
     * Ask its own parser/deparser for a stable representation, retaining every expression.
     * Temporary DDL is rolled back on error and never changes the business table.
     *
     * @param  array<string, array<string, mixed>>  $columns
     * @return array<string, array<string, mixed>>
     */
    private function canonicalGeneratedColumns(string $table, array $columns): array
    {
        $expressions = [];
        foreach ($columns as $name => $column) {
            if (is_array($column['generation'] ?? null) && is_string($column['generation']['expression'] ?? null)) {
                $expressions[] = $column['generation']['expression'].' AS '.$this->database->getQueryGrammar()->wrap($name);
            }
        }
        if ($expressions === []) {
            return $columns;
        }
        $canonical = $this->database->transaction(function () use ($table, $expressions): string {
            $view = 'ucg_erasure_schema_'.bin2hex(random_bytes(12));
            $pdo = $this->database->getPdo();
            $pdo->exec('CREATE TEMPORARY VIEW pg_temp."'.$view.'" AS SELECT '.implode(', ', $expressions)
                .' FROM '.$this->database->getQueryGrammar()->wrapTable($table));
            $definition = $this->database->scalar('SELECT pg_get_viewdef(?::regclass, false)', ['pg_temp.'.$view]);
            $pdo->exec('DROP VIEW pg_temp."'.$view.'"');
            if (! is_string($definition)) {
                throw new LogicException('Unable to canonicalize the erasure schema.');
            }

            return $definition;
        });
        foreach ($columns as &$column) {
            if (is_array($column['generation'] ?? null)) {
                $column['generation']['expression'] = $canonical;
            }
        }
        unset($column);

        return $columns;
    }

    /** @param ErasureManifestData $manifest
     * @return list<string>
     */
    private function sharedReferences(string $org, string $person, array $manifest): array
    {
        $blockers = [];
        if ($manifest['imports'] !== [] && $this->database->table('person_import_rows')->where('organization_id', $org)
            ->whereIn('import_id', $manifest['imports'])->where(fn (Builder $query) => $query->whereNull('person_id')->orWhere('person_id', '<>', $person))->exists()) {
            $blockers[] = 'person_imports:shared_import';
        }
        $excluded = [...self::OWNED, ...self::PROTECTED];
        foreach (array_diff(array_keys(DatabasePortabilityRepository::TABLES), $excluded) as $table) {
            $examined = 0;
            foreach ($this->database->table($table)->where('organization_id', $org)->orderBy(DatabasePortabilityRepository::TABLES[$table])->limit(100001)->cursor() as $row) {
                if (++$examined > 100000) {
                    throw new DomainConflictException(__('retention.erasure_limit'), 'ERASURE_LIMIT');
                }
                foreach ((array) $row as $column => $value) {
                    if (is_string($value) && str_contains(strtolower($value), strtolower($person))) {
                        $blockers[] = $table.(str_ends_with($column, 'person_id') && $value === $person ? ':shared_person_reference' : ':shared_snapshot_reference');
                        break 2;
                    }
                }
            }
        }
        $artifactIds = array_column(array_values(array_filter($manifest['files'], static fn ($file): bool => $file['kind'] === 'artifact')), 'id');
        if ($artifactIds !== []) {
            foreach (array_diff(array_keys(DatabasePortabilityRepository::TABLES), ['tenant_artifacts', 'person_imports', ...self::PROTECTED]) as $table) {
                if ($this->database->getSchemaBuilder()->hasColumn($table, 'artifact_id')
                    && $this->database->table($table)->where('organization_id', $org)->whereIn('artifact_id', $artifactIds)->exists()) {
                    $blockers[] = $table.':shared_artifact_reference';
                }
            }
        }

        return array_values(array_unique($blockers));
    }

    /** @param ErasureOperation $operation
     * @param  array{deleted: int, files_cursor: int, detached: bool}  $progress
     * @param  array<string, string>  $receipts
     * @return ErasureOperation
     */
    private function save(array $operation, string $status, array $progress, array $receipts, int $actorId, string $requestId, string $action): array
    {
        $version = $operation['version'] + 1;
        $updated = $this->database->table('tenant_erasure_operations')->where('organization_id', $operation['organization_id'])
            ->where('id', $operation['id'])->where('version', $operation['version'])->update(['status' => $status, 'progress' => json_encode($progress, JSON_THROW_ON_ERROR),
                'copy_receipts' => json_encode($receipts, JSON_THROW_ON_ERROR), 'version' => $version, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new LogicException('Concurrent erasure progress update.');
        }
        $this->event($operation['organization_id'], $operation['id'], $version, $actorId, $requestId, $action,
            ['progress' => $progress, 'copy_receipts' => $receipts, 'status' => $status]);

        return $this->find($operation['organization_id'], $operation['id']);
    }

    /** @param array<string, mixed> $evidence */
    private function event(string $org, string $id, int $version, int $actor, string $request, string $action, array $evidence): void
    {
        $this->database->table('tenant_erasure_events')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org, 'operation_id' => $id,
            'version' => $version, 'actor_user_id' => $actor, 'request_id' => $request, 'action' => $action,
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }
}
