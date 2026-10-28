<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use App\Modules\Tenancy\Domain\ErasureManifest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use LogicException;

/** @phpstan-import-type ErasureOperation from \App\Modules\Tenancy\Domain\Contracts\ManualErasureRepository
 * @phpstan-import-type ErasureIntent from ErasureJournal
 */
final readonly class IndependentErasureJournal implements ErasureJournal
{
    public function __construct(private DatabaseManager $databases, private Repository $config, private Application $app) {}

    public function assertReady(): void
    {
        $connection = $this->connection();
        if (! $connection->getSchemaBuilder()->hasTable('retention_erasure_journal') || ! $connection->getSchemaBuilder()->hasTable('retention_preservation_journal')) {
            throw new DomainConflictException(__('retention.journal_missing'), 'ERASURE_JOURNAL_UNAVAILABLE');
        }
        $this->key();
    }

    public function initialize(): void
    {
        $this->connection()->transaction(fn () => $this->installJournal());
    }

    private function installJournal(): void
    {
        $connection = $this->connection();
        $this->key();
        if (! $connection->getSchemaBuilder()->hasTable('retention_preservation_journal')) {
            $connection->getSchemaBuilder()->create('retention_preservation_journal', function (Blueprint $table): void {
                $table->uuid('installation_id');
                $table->uuid('request_id');
                $table->uuid('organization_id');
                $table->text('payload');
                $table->char('signature', 64);
                $table->primary(['installation_id', 'request_id']);
                $table->index(['installation_id', 'organization_id']);
            });
            if ($connection->getDriverName() === 'pgsql') {
                $connection->unprepared("CREATE FUNCTION ucg_independent_preservation_guard() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Preservation journal is append only' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER independent_preservation_guard BEFORE UPDATE OR DELETE ON retention_preservation_journal FOR EACH ROW EXECUTE FUNCTION ucg_independent_preservation_guard();");
            } else {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    $connection->unprepared("CREATE TRIGGER independent_preservation_{$operation}_guard BEFORE {$operation} ON retention_preservation_journal BEGIN SELECT RAISE(ABORT, 'Preservation journal is append only'); END;");
                }
            }
        }
        if ($connection->getSchemaBuilder()->hasTable('retention_erasure_journal')) {
            return;
        }
        $connection->getSchemaBuilder()->create('retention_erasure_journal', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('installation_id');
            $table->uuid('id');
            $table->uuid('organization_id');
            $table->text('payload');
            $table->char('signature', 64);
            $table->timestampTz('created_at');
            $table->unique(['installation_id', 'id'], 'erasure_journal_identity');
            $table->index(['installation_id', 'organization_id', 'sequence'], 'erasure_journal_scope');
        });
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared("CREATE FUNCTION ucg_independent_erasure_journal_guard() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Erasure journal is append only' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER independent_erasure_journal_guard BEFORE UPDATE OR DELETE ON retention_erasure_journal FOR EACH ROW EXECUTE FUNCTION ucg_independent_erasure_journal_guard();");
        } else {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $connection->unprepared("CREATE TRIGGER independent_erasure_journal_{$operation}_guard BEFORE {$operation} ON retention_erasure_journal BEGIN SELECT RAISE(ABORT, 'Erasure journal is append only'); END;");
            }
        }
    }

    public function append(array $operation): void
    {
        $this->assertReady();
        $payload = ErasureManifest::canonical(['format' => 1, 'installation_id' => $this->installation(), 'id' => $operation['id'],
            'organization_id' => $operation['organization_id'], 'scope' => $operation['scope'], 'target_id' => $operation['target_id'],
            'source_digest' => $operation['source_digest'], 'schema_digest' => $operation['schema_digest'], 'reference' => $operation['reference'],
            'manifest' => $operation['manifest'], 'created_at' => $operation['created_at']]);
        if (strlen($payload) > 16 * 1024 * 1024) {
            throw new LogicException('The erasure journal manifest exceeds its bounded payload size.');
        }
        $signature = hash_hmac('sha256', $payload, $this->key());
        $connection = $this->connection();
        $connection->transaction(function () use ($connection, $operation, $payload, $signature): void {
            $connection->table('retention_erasure_journal')->insertOrIgnore(['installation_id' => $this->installation(),
                'id' => $operation['id'], 'organization_id' => $operation['organization_id'], 'payload' => $payload,
                'signature' => $signature, 'created_at' => now()]);
            $existing = $connection->table('retention_erasure_journal')->where('installation_id', $this->installation())->where('id', $operation['id'])->firstOrFail();
            if (! hash_equals($signature, (string) $existing->signature) || $payload !== $existing->payload) {
                throw new DomainConflictException(__('retention.journal_conflict'), 'ERASURE_JOURNAL_CONFLICT');
            }
        });
    }

    public function intents(string $organizationId, int $after, int $limit): array
    {
        $this->assertReady();
        $rows = $this->connection()->table('retention_erasure_journal')->where('installation_id', $this->installation())
            ->where('organization_id', $organizationId)->orderBy('sequence')->limit(min(1000, max(1, $limit)) + 1)->get();
        if ($after !== 0 || $rows->count() > min(1000, max(1, $limit))) {
            throw new LogicException('Untrusted restoration cursors are not accepted.');
        }
        $result = [];
        foreach ($rows as $row) {
            $payload = (string) $row->payload;
            if (strlen($payload) > 16 * 1024 * 1024 || ! hash_equals((string) $row->signature, hash_hmac('sha256', $payload, $this->key()))) {
                throw new DomainConflictException(__('retention.journal_conflict'), 'ERASURE_JOURNAL_CONFLICT');
            }
            $value = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($value) || count($value) !== 11 || ($value['format'] ?? null) !== 1
                || ($value['installation_id'] ?? null) !== $this->installation() || ($value['organization_id'] ?? null) !== $organizationId
                || ! is_string($value['id'] ?? null) || ! Str::isUuid($value['id'])
                || ! in_array($value['scope'] ?? null, ['person', 'organization'], true)
                || ! is_string($value['target_id'] ?? null) || ! Str::isUuid($value['target_id'])
                || ($value['scope'] === 'organization' && $value['target_id'] !== $organizationId)
                || ! is_string($value['reference'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $value['reference']) !== 1
                || ! is_string($value['source_digest'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $value['source_digest']) !== 1
                || ! is_string($value['schema_digest'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $value['schema_digest']) !== 1
                || ! is_string($value['created_at'] ?? null)) {
                throw new DomainConflictException(__('retention.journal_conflict'), 'ERASURE_JOURNAL_CONFLICT');
            }
            $result[] = ['id' => $value['id'], 'organization_id' => $organizationId, 'scope' => $value['scope'],
                'target_id' => $value['target_id'], 'source_digest' => $value['source_digest'], 'schema_digest' => $value['schema_digest'],
                'reference' => $value['reference'], 'manifest' => ErasureManifest::parse($value['manifest'] ?? null), 'created_at' => $value['created_at']];
        }

        return $result;
    }

    public function head(string $organizationId): string
    {
        $this->assertReady();
        $row = $this->connection()->table('retention_erasure_journal')->where('installation_id', $this->installation())
            ->where('organization_id', $organizationId)->selectRaw('COUNT(*) AS total, MAX(sequence) AS last_sequence')->firstOrFail();

        return hash_hmac('sha256', $this->installation().':'.$organizationId.':'.$row->total.':'.($row->last_sequence ?? 0), $this->key());
    }

    public function preserve(string $organizationId, string $requestId, string $reference): void
    {
        $this->assertReady();
        $payload = ErasureManifest::canonical(['format' => 1, 'installation_id' => $this->installation(),
            'organization_id' => $organizationId, 'request_id' => $requestId, 'reference' => $reference]);
        $signature = hash_hmac('sha256', $payload, $this->key());
        $query = $this->connection()->table('retention_preservation_journal');
        $query->insertOrIgnore(['installation_id' => $this->installation(), 'organization_id' => $organizationId,
            'request_id' => $requestId, 'payload' => $payload, 'signature' => $signature]);
        $row = $this->connection()->table('retention_preservation_journal')->where('installation_id', $this->installation())->where('request_id', $requestId)->firstOrFail();
        if ($row->payload !== $payload || ! hash_equals((string) $row->signature, $signature)) {
            throw new DomainConflictException(__('retention.journal_conflict'), 'ERASURE_JOURNAL_CONFLICT');
        }
    }

    public function preservations(string $organizationId): array
    {
        $this->assertReady();
        $rows = $this->connection()->table('retention_preservation_journal')->where('installation_id', $this->installation())
            ->where('organization_id', $organizationId)->orderBy('request_id')->limit(1001)->get();
        if ($rows->count() > 1000) {
            throw new DomainConflictException(__('retention.erasure_limit'), 'ERASURE_LIMIT');
        }
        $records = [];
        foreach ($rows as $row) {
            $value = json_decode((string) $row->payload, true, 16, JSON_THROW_ON_ERROR);
            if (! hash_equals((string) $row->signature, hash_hmac('sha256', (string) $row->payload, $this->key()))
                || ! is_array($value) || count($value) !== 5 || ($value['format'] ?? null) !== 1
                || ($value['installation_id'] ?? null) !== $this->installation() || ($value['organization_id'] ?? null) !== $organizationId
                || ($value['request_id'] ?? null) !== $row->request_id || ! is_string($value['request_id']) || ! Str::isUuid($value['request_id'])
                || ! is_string($value['reference'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $value['reference']) !== 1) {
                throw new DomainConflictException(__('retention.journal_conflict'), 'ERASURE_JOURNAL_CONFLICT');
            }
            $records[] = ['request_id' => $value['request_id'], 'reference' => $value['reference']];
        }

        return $records;
    }

    private function connection(): Connection
    {
        $name = $this->config->get('retention.erasure.journal_connection');
        if (! is_string($name) || $name === '' || $name === $this->databases->getDefaultConnection()
            || $this->config->get('retention.erasure.journal_independence_reviewed') !== true) {
            throw new DomainConflictException(__('retention.journal_missing'), 'ERASURE_JOURNAL_UNAVAILABLE');
        }
        $source = $this->databases->connection();
        $journal = $this->databases->connection($name);
        if (! in_array($journal->getDriverName(), ['pgsql', 'sqlite'], true) || $journal->getPdo() === $source->getPdo()
            || ($journal->getDatabaseName() === $source->getDatabaseName()
                && ! ($this->app->environment('testing') && $journal->getDriverName() === 'sqlite' && $journal->getDatabaseName() === ':memory:'))
            || (! $this->app->environment('testing') && $journal->getDatabaseName() === ':memory:')) {
            throw new DomainConflictException(__('retention.journal_missing'), 'ERASURE_JOURNAL_UNAVAILABLE');
        }
        $this->installation();

        return $journal;
    }

    private function installation(): string
    {
        $value = $this->config->get('retention.erasure.installation_id');
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new DomainConflictException(__('retention.journal_missing'), 'ERASURE_JOURNAL_UNAVAILABLE');
        }

        return $value;
    }

    private function key(): string
    {
        $value = $this->config->get('retention.erasure.journal_key');
        if (! is_string($value) || strlen($value) < 64 || strlen($value) > 256 || $value === $this->config->get('app.key')) {
            throw new DomainConflictException(__('retention.journal_missing'), 'ERASURE_JOURNAL_UNAVAILABLE');
        }

        return $value;
    }
}
