<?php

namespace App\Support\Operations;

use App\Contracts\Operations\BackupEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Throwable;

final readonly class BackupDiagnostics
{
    public function __construct(private BackupEvidence $evidence, private Repository $config, private MonitoringScope $scope) {}

    public function inspect(bool $requested): RuntimeCheck
    {
        if (! $requested) {
            return new RuntimeCheck(ProbeState::Unverified, 'PROBE_NOT_REQUESTED');
        }
        if ($this->config->get('operations.backups.enabled') !== true) {
            return new RuntimeCheck(ProbeState::Unverified, 'BACKUP_MONITORING_DISABLED');
        }
        try {
            $data = $this->evidence->read();
            if (($data['version'] ?? null) !== 1 || ($data['namespace'] ?? null) !== $this->scope->namespace()
                || ! in_array($data['scope'] ?? null, ['local_simulation', 'hosted'], true)
                || ! is_string($data['manifest_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $data['manifest_sha256']) !== 1) {
                return new RuntimeCheck(ProbeState::Failed, 'BACKUP_EVIDENCE_SCHEMA_INVALID');
            }
            foreach (['backup_id', 'key_id'] as $field) {
                if (! is_string($data[$field] ?? null) || ! Str::isUuid($data[$field])) {
                    return new RuntimeCheck(ProbeState::Failed, 'BACKUP_EVIDENCE_SCHEMA_INVALID');
                }
            }
            foreach (['base_verified', 'wal_chain_verified', 'copy_verified'] as $field) {
                if (! is_bool($data[$field] ?? null)) {
                    return new RuntimeCheck(ProbeState::Failed, 'BACKUP_EVIDENCE_SCHEMA_INVALID');
                }
            }
            $skew = $this->scope->integer('backups.clock_skew_seconds', 0, 300);
            $generated = $this->age($data['generated_at'] ?? null, $skew);
            $backup = $this->age($data['backup_completed_at'] ?? null, $skew);
            $wal = $this->age($data['wal_archived_at'] ?? null, $skew);
            $restore = ($data['restore_tested_at'] ?? null) === null ? null : $this->age($data['restore_tested_at'], $skew);
            $maxBackup = $this->scope->integer('backups.max_backup_age_seconds', 60, 2678400);
            $maxWal = $this->scope->integer('backups.max_wal_age_seconds', 60, 86400);
            $maxRestore = $this->scope->integer('backups.max_restore_age_seconds', 60, 31536000);
            $observations = ['scope' => $data['scope'], 'receipt_age_seconds' => $generated,
                'backup_age_seconds' => $backup, 'wal_age_seconds' => $wal, 'restore_test_age_seconds' => $restore,
                'vendor_copy_and_rpo_rto_verified' => false];
            if (! $data['base_verified'] || ! $data['wal_chain_verified'] || ! $data['copy_verified']) {
                return new RuntimeCheck(ProbeState::Warning, 'BACKUP_EVIDENCE_INCOMPLETE', $observations);
            }
            if ($generated >= $maxBackup || $backup >= $maxBackup || $wal >= $maxWal) {
                return new RuntimeCheck(ProbeState::Warning, 'BACKUP_OR_WAL_STALE', $observations);
            }
            if ($restore === null || $restore >= $maxRestore) {
                return new RuntimeCheck(ProbeState::Warning, 'BACKUP_RESTORE_DRILL_REQUIRED', $observations);
            }

            return new RuntimeCheck($data['scope'] === 'hosted' ? ProbeState::Passed : ProbeState::Unverified,
                $data['scope'] === 'hosted' ? 'BACKUP_DECLARATION_CURRENT' : 'BACKUP_LOCAL_SIMULATION', $observations);
        } catch (Throwable $exception) {
            $safe = ['BACKUP_EVIDENCE_PATH_REFUSED', 'BACKUP_EVIDENCE_SIGNATURE_INVALID', 'BACKUP_EVIDENCE_READ_FAILED', 'BACKUP_EVIDENCE_SCHEMA_INVALID'];
            $code = in_array($exception->getMessage(), $safe, true) ? $exception->getMessage() : 'BACKUP_EVIDENCE_INVALID';

            return new RuntimeCheck(ProbeState::Failed, $code);
        }
    }

    private function age(mixed $value, int $skew): int
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $value) !== 1) {
            throw new \RuntimeException('BACKUP_EVIDENCE_SCHEMA_INVALID');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, 'UTC');
        if ($date === null || $date->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new \RuntimeException('BACKUP_EVIDENCE_SCHEMA_INVALID');
        }
        $age = now()->getTimestamp() - $date->getTimestamp();
        if ($age < -$skew) {
            throw new \RuntimeException('BACKUP_EVIDENCE_SCHEMA_INVALID');
        }

        return max(0, $age);
    }
}
