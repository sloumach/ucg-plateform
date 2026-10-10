<?php

namespace App\Architecture\Database;

use Illuminate\Database\DatabaseManager;

final readonly class TenantSchemaInspector
{
    public function __construct(private DatabaseManager $database) {}

    /** @return list<string> Metadata only: never loads tenant records or repairs the schema. */
    public function violations(): array
    {
        $schema = $this->database->connection()->getSchemaBuilder();
        $currentSchema = $schema->getCurrentSchemaName();
        $violations = [];
        if (! $schema->hasTable('organizations')) {
            $violations[] = __('tenancy.schema.root_required');
        }

        foreach ($schema->getTableListing($currentSchema, schemaQualified: false) as $table) {
            if (TableOwnership::isGlobal($table)) {
                continue;
            }

            $columns = array_column($schema->getColumns($table), null, 'name');
            if (! isset($columns['organization_id']) || $columns['organization_id']['nullable']) {
                $violations[] = __('tenancy.schema.organization_required', ['table' => $table]);
            }

            $foreignKeys = $schema->getForeignKeys($table);
            $rootForeignKey = false;
            foreach ($foreignKeys as $foreignKey) {
                if ($foreignKey['foreign_schema'] !== null && $foreignKey['foreign_schema'] !== $currentSchema) {
                    $violations[] = __('tenancy.schema.cross_schema', ['table' => $table]);

                    continue;
                }
                if ($foreignKey['columns'] === ['organization_id']
                    && $foreignKey['foreign_table'] === 'organizations'
                    && $foreignKey['foreign_columns'] === ['id']) {
                    $rootForeignKey = true;
                }
                if (! TableOwnership::isGlobal($foreignKey['foreign_table'])) {
                    $localPosition = array_search('organization_id', $foreignKey['columns'], true);
                    $remotePosition = array_search('organization_id', $foreignKey['foreign_columns'], true);
                    if ($localPosition === false || $localPosition !== $remotePosition) {
                        $violations[] = __('tenancy.schema.unsafe_relation', ['table' => $table, 'target' => $foreignKey['foreign_table']]);
                    }
                }
            }
            if (! $rootForeignKey) {
                $violations[] = __('tenancy.schema.root_foreign_key', ['table' => $table]);
            }

            $tenantIndex = false;
            foreach ($schema->getIndexes($table) as $index) {
                if (($index['columns'][0] ?? null) === 'organization_id') {
                    $tenantIndex = true;
                }
                if ($index['unique'] && (! $index['primary'] || $index['columns'] !== ['id'])
                    && ! in_array('organization_id', $index['columns'], true)) {
                    $violations[] = __('tenancy.schema.unsafe_unique', ['table' => $table, 'index' => $index['name']]);
                }
            }
            if (! $tenantIndex) {
                $violations[] = __('tenancy.schema.tenant_index', ['table' => $table]);
            }
        }

        sort($violations);

        return $violations;
    }
}
