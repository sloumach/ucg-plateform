<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use Illuminate\Database\Connectors\SQLiteConnector;
use LogicException;
use PDO;

/** Register the default denial once per physical PDO, while preserving Laravel's lazy connection. */
final class RetentionSqliteConnector extends SQLiteConnector
{
    /** @param array<string, mixed> $config */
    public function connect(array $config): PDO
    {
        $pdo = parent::connect($config);
        if (! $pdo->sqliteCreateFunction('ucg_manual_erasure_allowed', static fn (): int => 0, 4)) {
            throw new LogicException('The SQLite erasure denial function could not be registered.');
        }

        return $pdo;
    }
}
