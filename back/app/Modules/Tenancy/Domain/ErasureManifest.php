<?php

namespace App\Modules\Tenancy\Domain;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** @phpstan-import-type ErasureManifest from Contracts\ManualErasureRepository as ManifestData */
final class ErasureManifest
{
    /** @return ManifestData */
    public static function parse(mixed $value): array
    {
        if (! is_array($value) || count($value) !== 4 || ! isset($value['files'], $value['imports'], $value['order'], $value['detach'])
            || ! is_array($value['files']) || ! array_is_list($value['files']) || count($value['files']) > 100000
            || ! is_array($value['imports']) || ! array_is_list($value['imports']) || ! is_array($value['order']) || ! array_is_list($value['order'])
            || ! is_array($value['detach'])) {
            throw new InvalidArgumentException('Invalid erasure manifest.');
        }
        $files = [];
        foreach ($value['files'] as $file) {
            if (! is_array($file) || count($file) !== 3 || ! is_string($file['id'] ?? null) || ! Str::isUuid($file['id'])
                || ! is_string($file['disk'] ?? null) || preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $file['disk']) !== 1
                || ! in_array($file['kind'] ?? null, ['artifact', 'export'], true)) {
                throw new InvalidArgumentException('Invalid erasure file.');
            }
            $files[] = ['id' => $file['id'], 'disk' => $file['disk'], 'kind' => $file['kind']];
        }
        $imports = [];
        foreach ($value['imports'] as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new InvalidArgumentException('Invalid erasure import reference.');
            }
            $imports[] = $id;
        }
        $order = [];
        foreach ($value['order'] as $table) {
            if (! is_string($table) || ! in_array($table, RetentionCatalog::tables(), true)) {
                throw new InvalidArgumentException('Invalid erasure table.');
            }
            $order[] = $table;
        }
        $detach = [];
        foreach ($value['detach'] as $table => $columns) {
            if (! is_string($table) || ! in_array($table, $order, true) || ! is_array($columns) || ! array_is_list($columns)) {
                throw new InvalidArgumentException('Invalid erasure dependency.');
            }
            $detach[$table] = [];
            foreach ($columns as $column) {
                if (! is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $column) !== 1 || in_array($column, ['id', 'organization_id'], true)) {
                    throw new InvalidArgumentException('Invalid erasure dependency column.');
                }
                $detach[$table][] = $column;
            }
        }

        return ['files' => $files, 'imports' => $imports, 'order' => $order, 'detach' => $detach];
    }

    /** @param array<array-key, mixed> $value */
    public static function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
