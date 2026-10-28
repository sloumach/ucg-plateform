<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Modules\Tenancy\Domain\ErasureManifest;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class ScopedErasurePermit
{
    /** @var list<Closure> */
    private array $sqlitePredicates = [];

    public function __construct(private readonly Connection $database, private readonly RetentionControlRepository $controls) {}

    /** @template T
     * @param  array<string, mixed>|null  $expected
     * @param  Closure(): T  $action
     * @return T
     */
    public function run(string $organizationId, string $operationId, string $table, string $key, string $value, ?array $expected, Closure $action): mixed
    {
        if ($this->database->transactionLevel() < 1 || (DatabasePortabilityRepository::TABLES[$table] ?? null) !== $key
            || in_array($table, ['organization_retention_events', 'tenant_erasure_operations', 'tenant_erasure_events', 'tenant_erasure_permits', 'tenant_portability_operations'], true)
            || ($table === 'organizations' && $expected === null)) {
            throw new LogicException('Invalid scoped erasure permit.');
        }
        $state = $this->controls->state($organizationId);
        if ($state['holds'] !== [] || $state['quarantine'] !== 'erasure:'.$operationId) {
            throw new LogicException('Erasure requires its own quarantine and no preservation hold.');
        }
        $operation = $this->database->table('tenant_erasure_operations')->where('organization_id', $organizationId)->where('id', $operationId)->where('status', 'erasing')->firstOrFail();
        $manifest = ErasureManifest::parse(json_decode((string) $operation->manifest, true, 64, JSON_THROW_ON_ERROR));
        if ($expected === null && ! in_array($table, $manifest['order'], true)) {
            throw new LogicException('Erasure cannot remove a table outside its approved scope.');
        }
        $row = $this->database->table($table)->where($table === 'organizations' ? 'id' : 'organization_id', $organizationId)->where($key, $value)->firstOrFail();
        if ($expected !== null) {
            $valid = match ($table) {
                'organizations' => $operation->scope === 'organization' && $expected === ['name' => '[Erased organization]', 'slug' => 'erased-'.$organizationId, 'country' => 'ZZ', 'timezone' => 'UTC', 'language' => 'fr'],
                'tenant_storage_usage' => array_keys($expected) === ['bytes'] && is_int($expected['bytes']) && $expected['bytes'] >= 0 && $expected['bytes'] <= (int) $row->bytes,
                'dashboard_source_versions' => $operation->scope === 'person' && $expected === ['generation' => (int) $row->generation + 1],
                default => isset($manifest['detach'][$table]) && $expected === array_fill_keys($manifest['detach'][$table], null),
            };
            if (! $valid) {
                throw new LogicException('Erasure metadata changes must match the approved dependency plan.');
            }
        } elseif ($operation->scope === 'person') {
            $owned = match ($table) {
                'people' => $value === $operation->target_id,
                'person_game_profiles', 'person_periods', 'person_departures', 'person_departure_items' => $row->person_id === $operation->target_id,
                'person_external_accounts' => $this->database->table('person_game_profiles')->where('organization_id', $organizationId)->where('person_id', $operation->target_id)->where('id', $row->game_profile_id)->exists(),
                'person_imports' => in_array($value, $manifest['imports'], true),
                'person_import_rows' => in_array($row->import_id, $manifest['imports'], true) && $row->person_id === $operation->target_id,
                'tenant_artifacts' => in_array($value, array_column(array_filter($manifest['files'], static fn (array $file): bool => $file['kind'] === 'artifact'), 'id'), true),
                default => false,
            };
            if (! $owned) {
                throw new LogicException('A person erasure cannot remove another record.');
            }
        }
        $id = (string) Str::uuid7();
        $pdo = $this->database->getPdo();
        $pgsql = $this->database->getDriverName() === 'pgsql';
        if ($pgsql) {
            $binding = $this->database->selectOne('SELECT txid_current()::text AS transaction_id, pg_backend_pid() AS backend_id');
            $this->database->table('tenant_erasure_permits')->insert(['id' => $id, 'organization_id' => $organizationId,
                'operation_id' => $operationId, 'table_name' => $table, 'key_column' => $key, 'row_key' => $value,
                'expected' => $expected === null ? null : json_encode($expected, JSON_THROW_ON_ERROR),
                'transaction_id' => $binding->transaction_id, 'backend_id' => $binding->backend_id, 'control_version' => $state['version']]);
        } else {
            $predicate = $this->predicate($organizationId, $table, $key, $value, $expected, $state['version']);
            $this->sqlitePredicates[] = $predicate;
            $pdo->sqliteCreateFunction('ucg_manual_erasure_allowed', $predicate, 4);
        }
        $failure = null;
        try {
            return $action();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            if ($pgsql) {
                try {
                    $this->database->table('tenant_erasure_permits')->where('id', $id)->delete();
                } catch (Throwable $cleanup) {
                    // A failed PostgreSQL statement aborts this transaction; its rollback removes the permit.
                    if ($failure === null) {
                        throw $cleanup;
                    }
                }
            } else {
                array_pop($this->sqlitePredicates);
                $pdo->sqliteCreateFunction('ucg_manual_erasure_allowed', end($this->sqlitePredicates) ?: static fn (): int => 0, 4);
            }
        }
    }

    /** @param array<string, mixed>|null $expected */
    private function predicate(string $organizationId, string $table, string $key, string $value, ?array $expected, int $version): Closure
    {
        return function (string $actualTable, ?string $oldJson, ?string $newJson, string $operation) use ($table, $organizationId, $key, $value, $expected, $version): int {
            if ($actualTable !== $table || $oldJson === null || ! in_array($operation, ['DELETE', 'UPDATE'], true)) {
                return 0;
            }
            $old = json_decode($oldJson, true, 32, JSON_THROW_ON_ERROR);
            if (($old[$table === 'organizations' ? 'id' : 'organization_id'] ?? null) !== $organizationId || (string) ($old[$key] ?? '') !== $value
                || (int) $this->database->table('organizations')->where('id', $organizationId)->value('retention_version') !== $version) {
                return 0;
            }
            if ($expected === null) {
                return $operation === 'DELETE' ? 1 : 0;
            }
            if ($operation !== 'UPDATE' || $newJson === null) {
                return 0;
            }
            $new = json_decode($newJson, true, 32, JSON_THROW_ON_ERROR);
            if (($new[$key] ?? null) !== ($old[$key] ?? null)
                || ($new['organization_id'] ?? null) !== ($old['organization_id'] ?? null)
                || ($table === 'organizations' && ($new['owner_user_id'] ?? null) !== ($old['owner_user_id'] ?? null))) {
                return 0;
            }
            foreach ($old as $column => $previous) {
                if (($new[$column] ?? null) !== $previous && (! array_key_exists($column, $expected) || $new[$column] !== $expected[$column])) {
                    return 0;
                }
            }
            foreach ($expected as $column => $next) {
                if (! array_key_exists($column, $new) || $new[$column] !== $next) {
                    return 0;
                }
            }

            return 1;
        };
    }
}
