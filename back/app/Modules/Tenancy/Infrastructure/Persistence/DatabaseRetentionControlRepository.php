<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

final readonly class DatabaseRetentionControlRepository implements RetentionControlRepository
{
    public function __construct(private ConnectionInterface $database, private ErasureJournal $journal, private Repository $config) {}

    public function state(string $organizationId): array
    {
        $row = $this->database->table('organization_retention_events')->where('organization_id', $organizationId)
            ->orderByDesc('version')->first(['version', 'state']);
        if ($row === null) {
            return ['version' => 0, 'holds' => [], 'restricted_archive' => false, 'quarantine' => null];
        }
        $state = json_decode((string) $row->state, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($state) || count($state) !== 4 || ($state['version'] ?? null) !== (int) $row->version
            || ! is_array($state['holds'] ?? null) || ! array_is_list($state['holds']) || count($state['holds']) > 1000
            || ! is_bool($state['restricted_archive'] ?? null) || ! array_key_exists('quarantine', $state)
            || ($state['quarantine'] !== null && ! $this->reference($state['quarantine']))) {
            throw new LogicException('Invalid retention control state.');
        }
        foreach ($state['holds'] as $reference) {
            if (! $this->reference($reference)) {
                throw new LogicException('Invalid retention hold reference.');
            }
        }

        return ['version' => (int) $row->version, 'holds' => $state['holds'],
            'restricted_archive' => $state['restricted_archive'], 'quarantine' => $state['quarantine']];
    }

    public function request(string $organizationId, string $requestId): ?array
    {
        $row = $this->database->table('organization_retention_events')->where('organization_id', $organizationId)
            ->where('request_id', $requestId)->first(['version', 'action', 'reference', 'actor_user_id', 'reason']);

        return $row === null ? null : (array) $row;
    }

    public function append(string $organizationId, int $actorId, string $requestId, string $reason, string $action, string $reference, array $state, ?array $evidence = null): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('Retention events require the organization transaction lock.');
        }
        if ($action === 'hold' && $this->config->get('retention.erasure.journal_connection') !== null) {
            // Persist positive preservation before acknowledging it; a source rollback must fail safe.
            $this->journal->preserve($organizationId, $requestId, $reference);
        }
        $this->database->table('organization_retention_events')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'actor_user_id' => $actorId,
            'request_id' => $requestId, 'reason' => $reason, 'action' => $action, 'reference' => $reference,
            'version' => $state['version'], 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
            'evidence' => $evidence === null ? null : json_encode($evidence, JSON_THROW_ON_ERROR),
        ]);
        $updated = $this->database->table('organizations')->where('id', $organizationId)
            ->where('retention_version', $state['version'] - 1)->update(['retention_version' => $state['version']]);
        if ($updated !== 1) {
            throw new LogicException('Retention root revision is inconsistent with its audit.');
        }
    }

    private function reference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $value) === 1;
    }
}
