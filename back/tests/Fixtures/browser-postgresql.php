<?php

// Runs before Laravel boot: a matching name alone never proves database ownership.
$root = getenv('UCG_BROWSER_ROOT');
$token = getenv('UCG_BROWSER_TOKEN');
$port = getenv('DB_PORT');
$manifestPath = $root.'/postgres.json';
$dataPath = $root.'/pgsql';
$refuse = static function (): never {
    throw new RuntimeException('Isolated browser environment required; temporary PostgreSQL ownership not proven.');
};
if (! is_string($root) || ! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
    || ! is_string($port) || ! ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_SSLMODE') !== 'disable'
    || getenv('DB_DATABASE') !== 'ucg_browser_'.substr($token, 0, 24)
    || getenv('DB_USERNAME') !== 'ucg_browser' || getenv('DB_PASSWORD') !== $token
    || ! is_file($manifestPath) || is_link($manifestPath)
    || ! is_dir($dataPath) || is_link($dataPath)
    || realpath($dataPath) !== realpath($root).DIRECTORY_SEPARATOR.'pgsql'
    || ! is_file($dataPath.'/PG_VERSION') || trim((string) file_get_contents($dataPath.'/PG_VERSION')) !== '17') {
    $refuse();
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (! is_array($manifest) || ($manifest['token'] ?? null) !== $token || ($manifest['port'] ?? null) !== $port) {
    $refuse();
}
try {
    $connection = new PDO(
        'pgsql:host=127.0.0.1;port='.$port.';dbname=ucg_browser_'.substr($token, 0, 24).';sslmode=disable;connect_timeout=3',
        'ucg_browser',
        $token,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $directoryQuery = $connection->query('SHOW data_directory');
    $versionQuery = $connection->query('SHOW server_version_num');
    if ($directoryQuery === false || $versionQuery === false) {
        $refuse();
    }
    $directory = $directoryQuery->fetchColumn();
    $version = $versionQuery->fetchColumn();
    if (! is_string($directory) || realpath($directory) !== realpath($dataPath)
        || (int) $version < 170000 || (int) $version >= 180000) {
        $refuse();
    }
} catch (PDOException) {
    // Never print credentials or fall back to the project's connection.
    $refuse();
}
unset($connection);
