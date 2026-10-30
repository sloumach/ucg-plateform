<?php

namespace App\Support\Operations;

use App\Contracts\Operations\BackupEvidence;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class FileBackupEvidence implements BackupEvidence
{
    private const FIELDS = ['version', 'namespace', 'scope', 'backup_id', 'key_id', 'generated_at',
        'backup_completed_at', 'wal_archived_at', 'restore_tested_at', 'base_verified',
        'wal_chain_verified', 'copy_verified', 'manifest_sha256'];

    public function __construct(private Repository $config) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $receipt = $this->privatePath('receipt_path', 65536);
        $key = $this->privatePath('key_path', 32);
        $archive = $this->config->get('operations.backups.archive_root');
        if (! is_string($archive) || ! is_dir($archive) || is_link($archive) || realpath($archive) === false
            || dirname($receipt) === dirname($key) || $this->within($key, $archive)
            || $this->within($key, storage_path('app'))) {
            throw new RuntimeException('BACKUP_EVIDENCE_PATH_REFUSED');
        }
        $secret = @file_get_contents($key);
        $contents = @file_get_contents($receipt);
        if (! is_string($secret) || strlen($secret) !== 32 || ! is_string($contents) || strlen($contents) > 65536) {
            throw new RuntimeException('BACKUP_EVIDENCE_READ_FAILED');
        }
        $envelope = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($envelope) || count($envelope) !== 2 || ! is_string($envelope['payload'] ?? null)
            || strlen($envelope['payload']) > 32768 || ! is_string($envelope['signature'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $envelope['signature']) !== 1
            || ! hash_equals(hash_hmac('sha256', $envelope['payload'], $secret), $envelope['signature'])) {
            throw new RuntimeException('BACKUP_EVIDENCE_SIGNATURE_INVALID');
        }
        $payload = json_decode($envelope['payload'], true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || count($payload) !== count(self::FIELDS)) {
            throw new RuntimeException('BACKUP_EVIDENCE_SCHEMA_INVALID');
        }
        $result = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new RuntimeException('BACKUP_EVIDENCE_SCHEMA_INVALID');
            }
            $result[$field] = $payload[$field];
        }

        return $result;
    }

    private function privatePath(string $setting, int $maximum): string
    {
        $path = $this->config->get('operations.backups.'.$setting);
        if (! is_string($path) || ! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new RuntimeException('BACKUP_EVIDENCE_PATH_REFUSED');
        }
        $resolved = realpath($path);
        $size = @filesize($path);
        if ($resolved === false || str_replace('\\', '/', $resolved) !== str_replace('\\', '/', $path)
            || ! is_int($size) || $size < 1 || $size > $maximum
            || $this->within($resolved, public_path()) || $this->within($resolved, storage_path('app/public'))) {
            throw new RuntimeException('BACKUP_EVIDENCE_PATH_REFUSED');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($resolved) & 0077) !== 0) {
            throw new RuntimeException('BACKUP_EVIDENCE_PATH_REFUSED');
        }

        return $resolved;
    }

    private function within(string $path, string $directory): bool
    {
        $resolved = realpath($directory);
        if ($resolved === false) {
            return false;
        }
        $path = str_replace('\\', '/', $path);
        $resolved = rtrim(str_replace('\\', '/', $resolved), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $resolved = strtolower($resolved);
        }

        return $path === $resolved || str_starts_with($path, $resolved.'/');
    }
}
