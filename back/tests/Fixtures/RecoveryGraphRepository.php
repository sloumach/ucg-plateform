<?php

namespace Tests\Fixtures;

use App\Architecture\Database\TableOwnership;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabasePortabilityRepository;
use Illuminate\Database\Connection;
use RuntimeException;

/** Closed, bounded fixture repository. Never registered as an application importer. */
final readonly class RecoveryGraphRepository
{
    public function __construct(private Connection $db, private DatabasePortabilityRepository $catalog) {}

    public function schemaHash(): string
    {
        $this->catalog->assertCatalog();

        return self::hash($this->db->select("SELECT table_name,column_name,data_type,udt_name,is_nullable,ordinal_position,is_generated FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position"));
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function rows(string $tenant): array
    {
        $rows = array_fill_keys(array_keys(DatabasePortabilityRepository::TABLES), []);
        foreach ($this->catalog->rows($tenant, 500) as $record) {
            $rows[$record['table']][] = $record['data'];
        }

        return $rows;
    }

    /** @param array<string, mixed> $source
     * @param  list<string>  $preserve
     * @return array{missing:array<string,array{table:string,data:array<string,mixed>}>,conflicts:list<string>,derived:list<string>,unresolved:list<string>,ordered:list<string>,batches:list<list<string>>,current_hash:string}
     */
    public function plan(string $tenant, array $source, array $preserve): array
    {
        if (array_keys($source) !== array_keys(DatabasePortabilityRepository::TABLES)) {
            throw new RuntimeException('GRAPH_CATALOG');
        }
        $current = $this->rows($tenant);
        $missing = [];
        $conflicts = [];
        $derived = [];
        $count = 0;
        foreach ($source as $table => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException('GRAPH_ROWS');
            }
            $columns = $this->db->getSchemaBuilder()->getColumnListing($table);
            if ($table === 'organization_access_events') {
                $columns = array_values(array_diff($columns, ['membership_id', 'invitation_id', 'role_id', 'role_assignment_id']));
            }
            $known = [];
            foreach ($current[$table] as $row) {
                $known[$this->key($table, $row)] = $row;
            }
            $seen = [];
            foreach ($rows as $row) {
                if (++$count > 500 || ! is_array($row) || array_diff(array_keys($row), $columns) !== [] || array_diff($columns, array_keys($row)) !== []) {
                    throw new RuntimeException('GRAPH_COLUMNS');
                }
                if (($row[$table === 'organizations' ? 'id' : 'organization_id'] ?? null) !== $tenant) {
                    throw new RuntimeException('GRAPH_SCOPE');
                }
                $key = $this->key($table, $row);
                if (isset($seen[$key])) {
                    throw new RuntimeException('GRAPH_DUPLICATE');
                }
                $seen[$key] = true;
                // Triggers regenerate these counters; old values defeat invalidation
                // and collide with rows created by the recovered business inserts.
                if ($table === 'dashboard_source_versions') {
                    $derived[] = $key;

                    continue;
                }
                if (isset($known[$key])) {
                    if (self::hash($known[$key]) !== self::hash($row)) {
                        $conflicts[] = $key;
                    }
                } else {
                    if ($table === 'organizations' || $table === 'tenant_portability_operations') {
                        throw new RuntimeException('GRAPH_ROOT_OR_OPERATION');
                    }
                    if (in_array($table, ['organization_memberships', 'organization_invitations', 'organization_roles', 'organization_role_assignments', 'organization_support_accesses'], true)
                        || ($table === 'people' && ($row['user_id'] ?? null) !== null)) {
                        throw new RuntimeException('GRAPH_ACCESS_REVIEW');
                    }
                    if (DatabasePortabilityRepository::TABLES[$table] === 'id' && $this->db->table($table)->where('id', $row['id'])->exists()) {
                        throw new RuntimeException('GRAPH_IDENTITY_COLLISION');
                    }
                    $missing[$key] = ['table' => $table, 'data' => $row];
                }
            }
        }
        if (array_diff($preserve, $conflicts) !== []) {
            throw new RuntimeException('GRAPH_DECISION');
        }
        foreach ($conflicts as $key) {
            if (str_starts_with($key, 'tenant_storage_usage:') || str_starts_with($key, 'tenant_artifacts:')) {
                throw new RuntimeException('GRAPH_STORAGE_ACCOUNTING');
            }
        }
        $dependencies = array_fill_keys(array_keys($missing), []);
        $foreignKeys = $this->db->select("SELECT c.conrelid::regclass::text AS child,c.confrelid::regclass::text AS parent,
            json_agg(a.attname ORDER BY x.n)::text AS columns,json_agg(b.attname ORDER BY x.n)::text AS targets
            FROM pg_constraint c CROSS JOIN LATERAL unnest(c.conkey,c.confkey) WITH ORDINALITY AS x(local_id,foreign_id,n)
            JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=x.local_id
            JOIN pg_attribute b ON b.attrelid=c.confrelid AND b.attnum=x.foreign_id
            WHERE c.contype='f' AND c.connamespace='public'::regnamespace GROUP BY c.oid,c.conrelid,c.confrelid");
        foreach ($missing as $key => $record) {
            foreach ($foreignKeys as $fk) {
                if ($fk->child !== $record['table']) {
                    continue;
                }
                /** @var list<string> $columns */
                $columns = json_decode($fk->columns, true, flags: JSON_THROW_ON_ERROR);
                /** @var list<string> $targets */
                $targets = json_decode($fk->targets, true, flags: JSON_THROW_ON_ERROR);
                $values = [];
                foreach ($columns as $index => $column) {
                    if (! isset($record['data'][$column])) {
                        continue 2;
                    }
                    $values[$targets[$index]] = $record['data'][$column];
                }
                if (! array_key_exists($fk->parent, DatabasePortabilityRepository::TABLES) && ! TableOwnership::isGlobal($fk->parent)) {
                    throw new RuntimeException('GRAPH_FOREIGN_CATALOG');
                }
                if ($this->db->table($fk->parent)->where($values)->exists()) {
                    continue;
                }
                $found = false;
                foreach ($missing as $parentKey => $parent) {
                    if ($parent['table'] === $fk->parent && array_intersect_assoc($values, $parent['data']) === $values) {
                        $dependencies[$key][] = $parentKey;
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    throw new RuntimeException('GRAPH_REFERENCE');
                }
            }
        }
        $batches = RecoveryGraphReplay::batches($missing, $dependencies);
        $ordered = [];
        while ($dependencies !== []) {
            $ready = array_keys(array_filter($dependencies, static fn (array $parents): bool => $parents === []));
            if ($ready === []) {
                throw new RuntimeException('GRAPH_CYCLE');
            }
            foreach ($ready as $key) {
                $ordered[] = $key;
                unset($dependencies[$key]);
            }
            foreach ($dependencies as &$parents) {
                $parents = array_values(array_diff($parents, $ready));
            }
            unset($parents);
        }

        return ['missing' => $missing, 'conflicts' => $conflicts, 'derived' => $derived, 'unresolved' => array_values(array_diff($conflicts, $preserve)),
            'ordered' => $ordered, 'batches' => $batches, 'current_hash' => self::hash($current)];
    }

    /** @param array<string,array{table:string,data:array<string,mixed>}> $records */
    public function insertBatch(array $records): void
    {
        (new RecoveryGraphReplay($this->db))->insert($records);
    }

    /** @param array<string, mixed> $row */
    public function key(string $table, array $row): string
    {
        $value = $row[DatabasePortabilityRepository::TABLES[$table]] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('GRAPH_IDENTITY');
        }

        return $table.':'.$value;
    }

    public static function hash(mixed $value): string
    {
        // Canonicalize associative keys, preserving list order and scalar types.
        $canonical = static function (mixed $item) use (&$canonical): mixed {
            if (is_object($item)) {
                $item = (array) $item;
            }
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }
                $item = array_map($canonical, $item);
            }

            return $item;
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }
}
