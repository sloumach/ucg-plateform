<?php

namespace Tests\Feature;

use App\Contracts\Operations\BackupEvidence;
use App\Support\Operations\AlertService;
use App\Support\Operations\BackupDiagnostics;
use App\Support\Operations\ProbeState;
use App\Support\Operations\RuntimeInspection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupDiagnosticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_default_and_unrequested_checks_do_not_read_files(): void
    {
        $this->mock(BackupEvidence::class)->shouldNotReceive('read');
        config(['operations.backups.enabled' => false]);
        $this->assertSame('BACKUP_MONITORING_DISABLED', app(BackupDiagnostics::class)->inspect(true)->code);
        config(['operations.backups.enabled' => true]);
        $this->assertSame('PROBE_NOT_REQUESTED', app(BackupDiagnostics::class)->inspect(false)->code);
    }

    public function test_local_evidence_never_claims_hosted_backups_are_verified(): void
    {
        $data = $this->configureEvidence();
        $this->writeEvidence($data);

        $check = app(BackupDiagnostics::class)->inspect(true);

        $this->assertSame(ProbeState::Unverified, $check->state);
        $this->assertSame('BACKUP_LOCAL_SIMULATION', $check->code);
        $this->assertFalse($check->observations['vendor_copy_and_rpo_rto_verified']);
        $this->assertStringNotContainsString(config('operations.backups.key_path'), json_encode($check->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_signed_hosted_declaration_only_reports_its_freshness(): void
    {
        $data = $this->configureEvidence();
        $data['scope'] = 'hosted';
        $this->writeEvidence($data);

        $check = app(BackupDiagnostics::class)->inspect(true);

        $this->assertSame(ProbeState::Passed, $check->state);
        $this->assertSame('BACKUP_DECLARATION_CURRENT', $check->code);
        $this->assertFalse($check->observations['vendor_copy_and_rpo_rto_verified']);
    }

    #[DataProvider('unhealthyDeclarations')]
    public function test_invalid_stale_incomplete_and_foreign_evidence_is_not_healthy(string $field, mixed $value, string $code): void
    {
        $data = $this->configureEvidence();
        $data[$field] = $value;
        $this->writeEvidence($data);

        $check = app(BackupDiagnostics::class)->inspect(true);

        $this->assertSame($code, $check->code);
        $this->assertNotSame(ProbeState::Passed, $check->state);
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function unhealthyDeclarations(): array
    {
        return [
            'namespace' => ['namespace', 'foreign_environment', 'BACKUP_EVIDENCE_SCHEMA_INVALID'],
            'bad date' => ['generated_at', 'sensitive-sentinel', 'BACKUP_EVIDENCE_SCHEMA_INVALID'],
            'future' => ['generated_at', '2026-10-30T12:00:31.000Z', 'BACKUP_EVIDENCE_SCHEMA_INVALID'],
            'invalid date' => ['generated_at', '2026-02-30T12:00:00.000Z', 'BACKUP_EVIDENCE_SCHEMA_INVALID'],
            'old backup' => ['backup_completed_at', '2026-10-29T12:00:00.000Z', 'BACKUP_OR_WAL_STALE'],
            'old WAL' => ['wal_archived_at', '2026-10-30T11:55:00.000Z', 'BACKUP_OR_WAL_STALE'],
            'missing restore' => ['restore_tested_at', null, 'BACKUP_RESTORE_DRILL_REQUIRED'],
            'old restore' => ['restore_tested_at', '2026-10-23T12:00:00.000Z', 'BACKUP_RESTORE_DRILL_REQUIRED'],
            'copy incomplete' => ['copy_verified', false, 'BACKUP_EVIDENCE_INCOMPLETE'],
            'chain incomplete' => ['wal_chain_verified', false, 'BACKUP_EVIDENCE_INCOMPLETE'],
            'base incomplete' => ['base_verified', false, 'BACKUP_EVIDENCE_INCOMPLETE'],
            'boolean string' => ['copy_verified', 'true', 'BACKUP_EVIDENCE_SCHEMA_INVALID'],
        ];
    }

    public function test_altered_signed_payload_is_rejected_without_printing_its_content(): void
    {
        $data = $this->configureEvidence();
        $this->writeEvidence($data);
        $path = config('operations.backups.receipt_path');
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $envelope = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $envelope['payload'] = str_replace('local_simulation', 'sensitive-sentinel', $envelope['payload']);
        file_put_contents($path, json_encode($envelope, JSON_THROW_ON_ERROR));

        $check = app(BackupDiagnostics::class)->inspect(true);

        $this->assertSame('BACKUP_EVIDENCE_SIGNATURE_INVALID', $check->code);
        $this->assertStringNotContainsString('sensitive-sentinel', json_encode($check->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_key_inside_archive_is_refused(): void
    {
        $data = $this->configureEvidence();
        $this->writeEvidence($data);
        config(['operations.backups.archive_root' => dirname(config('operations.backups.key_path'))]);
        $this->assertSame('BACKUP_EVIDENCE_PATH_REFUSED', app(BackupDiagnostics::class)->inspect(true)->code);
    }

    public function test_oversized_receipt_is_refused_before_decoding(): void
    {
        $this->configureEvidence();
        file_put_contents(config('operations.backups.receipt_path'), str_repeat('x', 65537));
        clearstatcache();
        $this->assertSame('BACKUP_EVIDENCE_PATH_REFUSED', app(BackupDiagnostics::class)->inspect(true)->code);
    }

    public function test_simulation_cannot_resolve_a_persisted_backup_failure(): void
    {
        $data = $this->configureEvidence();
        config(['operations.alerts.enabled' => true]);
        $data['wal_archived_at'] = '2026-10-30T11:00:00.000Z';
        $this->writeEvidence($data);
        $this->recordCheck();
        $this->assertDatabaseHas('operations_alerts', ['component' => 'backup_recovery', 'status' => 'open']);
        $data['wal_archived_at'] = '2026-10-30T12:00:00.000Z';
        $this->writeEvidence($data);
        $this->recordCheck();
        $this->assertDatabaseHas('operations_alerts', ['component' => 'backup_recovery', 'status' => 'open']);
        $data['scope'] = 'hosted';
        $this->writeEvidence($data);
        $this->recordCheck();
        $this->assertDatabaseHas('operations_alerts', ['component' => 'backup_recovery', 'status' => 'resolved']);
        $this->assertDatabaseCount('operations_alerts', 1);
        $this->assertSame(1, DB::table('operations_alert_events')->where('action', 'resolved')->count());
    }

    /** @return array<string, mixed> */
    private function configureEvidence(): array
    {
        $this->travelTo('2026-10-30 12:00:00 UTC');
        $disk = Storage::fake('backup-evidence');
        $root = $disk->path('');
        foreach (['receipts', 'keys', 'archive'] as $directory) {
            mkdir($root.$directory, 0700);
        }
        file_put_contents($root.'keys/evidence.key', str_repeat('k', 32));
        chmod($root.'keys/evidence.key', 0600);
        file_put_contents($root.'receipts/evidence.json', '{}');
        chmod($root.'receipts/evidence.json', 0600);
        config(['operations.backups.enabled' => true, 'operations.resource_namespace' => 'ops_backup_test',
            'operations.backups.receipt_path' => realpath($root.'receipts/evidence.json'),
            'operations.backups.key_path' => realpath($root.'keys/evidence.key'),
            'operations.backups.archive_root' => realpath($root.'archive')]);

        return ['version' => 1, 'namespace' => 'ops_backup_test', 'scope' => 'local_simulation',
            'backup_id' => (string) Str::uuid(), 'key_id' => (string) Str::uuid(),
            'generated_at' => '2026-10-30T12:00:00.000Z', 'backup_completed_at' => '2026-10-30T12:00:00.000Z',
            'wal_archived_at' => '2026-10-30T12:00:00.000Z', 'restore_tested_at' => '2026-10-30T12:00:00.000Z',
            'base_verified' => true, 'wal_chain_verified' => true, 'copy_verified' => true,
            'manifest_sha256' => str_repeat('a', 64)];
    }

    /** @param array<string, mixed> $data */
    private function writeEvidence(array $data): void
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents(config('operations.backups.receipt_path'), json_encode([
            'payload' => $payload, 'signature' => hash_hmac('sha256', $payload, str_repeat('k', 32)),
        ], JSON_THROW_ON_ERROR));
    }

    private function recordCheck(): void
    {
        app(AlertService::class)->synchronize(new RuntimeInspection(['backup_recovery' => app(BackupDiagnostics::class)->inspect(true)], true));
    }
}
