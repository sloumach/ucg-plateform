<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Architecture\Database\TableOwnership;
use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class DatabasePortabilityRepository implements PortabilityRepository
{
    /** Closed inventory; register new tenant tables explicitly before claiming a complete export. */
    public const array TABLES = [
        'organizations' => 'id', 'organization_settings' => 'organization_id',
        'organization_lifecycle_events' => 'id', 'organization_memberships' => 'id',
        'organization_invitations' => 'id', 'organization_access_events' => 'id',
        'organization_roles' => 'id', 'organization_role_assignments' => 'id',
        'tenant_storage_usage' => 'organization_id', 'tenant_artifacts' => 'id',
        'tenant_portability_operations' => 'id',
    ];

    /** Explicit physical columns: adding a field must not silently leak it into an archive. */
    private const array COLUMNS = [
        'organizations' => ['id', 'name', 'slug', 'owner_user_id', 'status', 'timezone', 'language', 'country', 'created_at', 'updated_at'],
        'organization_settings' => ['organization_id', 'week_starts_on', 'date_format', 'created_at', 'updated_at'],
        'organization_lifecycle_events' => ['id', 'organization_id', 'actor_user_id', 'action', 'from_status', 'to_status', 'reason', 'request_id', 'occurred_at'],
        'organization_memberships' => ['id', 'organization_id', 'user_id', 'status', 'roles', 'starts_at', 'ends_at', 'created_at', 'updated_at'],
        'organization_invitations' => ['id', 'organization_id', 'email', 'roles', 'status', 'invited_by', 'responded_by', 'starts_at', 'ends_at', 'expires_at', 'responded_at', 'created_at', 'updated_at'],
        'organization_access_events' => ['id', 'organization_id', 'actor_user_id', 'action', 'subject_id', 'before', 'after', 'request_id', 'occurred_at'],
        'organization_roles' => ['id', 'organization_id', 'key', 'name', 'permissions', 'active', 'privileged', 'version', 'created_at', 'updated_at'],
        'organization_role_assignments' => ['id', 'organization_id', 'role_id', 'user_id', 'membership_id', 'scope_type', 'scope_id', 'status', 'starts_at', 'ends_at', 'version', 'created_at', 'updated_at'],
        'tenant_storage_usage' => ['organization_id', 'bytes'],
        'tenant_artifacts' => ['id', 'organization_id', 'name', 'disk', 'bytes', 'ready', 'created_at', 'updated_at'],
        'tenant_portability_operations' => ['id', 'organization_id', 'actor_user_id', 'request_id', 'kind', 'status', 'reason', 'disk', 'bytes', 'sha256', 'expires_at', 'details', 'created_at', 'updated_at'],
    ];

    public function __construct(private DatabaseManager $database) {}

    public function assertCatalog(): void
    {
        $schema = $this->database->connection()->getSchemaBuilder();
        foreach ($schema->getTableListing($schema->getCurrentSchemaName(), schemaQualified: false) as $table) {
            if (! TableOwnership::isGlobal($table) && ! array_key_exists($table, self::TABLES)) {
                throw new DomainConflictException(__('tenancy.portability.catalog'), 'PORTABILITY_CATALOG_INCOMPLETE');
            }
        }
        foreach (array_keys(self::TABLES) as $table) {
            if (! $schema->hasTable($table)) {
                throw new DomainConflictException(__('tenancy.portability.catalog'), 'PORTABILITY_CATALOG_INCOMPLETE');
            }
            $actual = $schema->getColumnListing($table);
            $expected = self::COLUMNS[$table];
            // Generated FK projections are derived from subject_id and are not restore inputs.
            $generated = $table === 'organization_access_events' ? ['membership_id', 'invitation_id', 'role_id', 'role_assignment_id'] : [];
            if (array_diff($actual, [...$expected, ...$generated]) !== [] || array_diff($expected, $actual) !== []) {
                throw new DomainConflictException(__('tenancy.portability.catalog'), 'PORTABILITY_CATALOG_INCOMPLETE');
            }
        }
    }

    public function rows(string $organizationId, int $limit): iterable
    {
        $this->assertCatalog();
        if ($limit < 1) {
            throw new InvalidArgumentException('A complete export requires a positive row limit.');
        }
        $count = 0;
        foreach (self::TABLES as $table => $key) {
            foreach ($this->tenantQuery($table, $organizationId)->select(self::COLUMNS[$table])->lazyById(200, $key) as $row) {
                if (++$count > $limit) {
                    throw new TenantLimitExceededException;
                }
                yield ['table' => $table, 'data' => (array) $row];
            }
        }
    }

    public function counts(string $organizationId): array
    {
        $this->assertCatalog();
        $counts = [];
        foreach (array_keys(self::TABLES) as $table) {
            $counts[$table] = $this->tenantQuery($table, $organizationId)->count();
        }

        return $counts;
    }

    public function create(string $organizationId, array $attributes): PortabilityOperation
    {
        $id = (string) Str::uuid7();
        $this->database->connection()->table('tenant_portability_operations')->insert([
            ...$attributes, 'id' => $id, 'organization_id' => $organizationId,
            'details' => json_encode($attributes['details'] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->find($organizationId, $id);
    }

    public function find(string $organizationId, string $operationId): PortabilityOperation
    {
        if (! Str::isUuid($operationId)) {
            throw new ModelNotFoundException;
        }
        $row = $this->tenantQuery('tenant_portability_operations', $organizationId)->where('id', strtolower($operationId))->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return $this->operation($row);
    }

    public function update(string $organizationId, string $operationId, array $attributes): void
    {
        if (isset($attributes['details'])) {
            $attributes['details'] = json_encode($attributes['details'], JSON_THROW_ON_ERROR);
        }
        $this->tenantQuery('tenant_portability_operations', $organizationId)->where('id', $operationId)
            ->update([...$attributes, 'updated_at' => now()]);
    }

    public function reservedExportBytes(string $organizationId): int
    {
        return (int) $this->tenantQuery('tenant_portability_operations', $organizationId)->where('kind', 'export')
            ->whereIn('status', ['pending', 'ready', 'failed'])->sum('bytes');
    }

    public function exports(string $organizationId, int $limit): array
    {
        $this->assertLimit($limit);

        return array_values($this->tenantQuery('tenant_portability_operations', $organizationId)->where('kind', 'export')
            ->whereIn('status', ['pending', 'ready', 'failed'])->orderBy('id')->limit($limit)->get()
            ->map(fn (stdClass $row): PortabilityOperation => $this->operation($row))->all());
    }

    public function expiredExports(int $limit): array
    {
        $this->assertLimit($limit);

        return array_values($this->database->connection()->table('tenant_portability_operations')->where('kind', 'export')
            ->whereIn('status', ['pending', 'ready', 'failed'])->where('expires_at', '<=', now())->orderBy('updated_at')->orderBy('id')
            ->limit($limit)->get()->map(fn (stdClass $row): PortabilityOperation => $this->operation($row))->all());
    }

    public function artifacts(string $organizationId, int $limit): array
    {
        $this->assertLimit($limit);

        return array_values($this->tenantQuery('tenant_artifacts', $organizationId)->orderBy('id')->limit($limit)->get(['id', 'disk', 'bytes'])
            ->map(fn (stdClass $row): array => ['id' => (string) $row->id, 'disk' => (string) $row->disk, 'bytes' => (int) $row->bytes])->all());
    }

    public function removeSettings(string $organizationId): void
    {
        $this->tenantQuery('organization_settings', $organizationId)->delete();
    }

    public function hasPurge(string $organizationId): bool
    {
        return $this->tenantQuery('tenant_portability_operations', $organizationId)->where('kind', 'purge')->exists();
    }

    private function tenantQuery(string $table, string $organizationId): Builder
    {
        if (! Str::isUuid($organizationId) || ! array_key_exists($table, self::TABLES)) {
            throw new InvalidArgumentException('Portability requires an explicit tenant and a registered table.');
        }

        return $this->database->connection()->table($table)->where($table === 'organizations' ? 'id' : 'organization_id', strtolower($organizationId));
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Maintenance pages must contain between one and 200 records.');
        }
    }

    private function operation(stdClass $row): PortabilityOperation
    {
        $details = json_decode((string) $row->details, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($details)) {
            throw new InvalidArgumentException('Invalid operation details.');
        }

        return new PortabilityOperation((string) $row->id, (string) $row->organization_id, (int) $row->actor_user_id,
            (string) $row->request_id, (string) $row->kind, (string) $row->status, (string) $row->reason,
            $row->disk === null ? null : (string) $row->disk, $row->expires_at === null ? null : (string) $row->expires_at,
            (int) $row->bytes, $row->sha256 === null ? null : (string) $row->sha256, $details);
    }
}
