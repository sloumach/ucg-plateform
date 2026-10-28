<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final readonly class ErasureAccessGate
{
    public function __construct(private ErasureJournal $journal, private RetentionControlRepository $controls,
        private ConnectionInterface $database, private Repository $config) {}

    public function assertReadable(string $org, ?int $freshRevision = null): void
    {
        if ($freshRevision === 0 && $this->config->get('retention.erasure.journal_connection') === null
            && $this->config->get('retention.erasure.restore_mode') !== true) {
            return;
        }
        if ($this->config->get('retention.erasure.restore_mode') === true || $this->controls->state($org)['quarantine'] !== null) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }
        if ($this->config->get('retention.erasure.journal_connection') === null) {
            if ($this->database->table('tenant_erasure_operations')->where('organization_id', $org)->exists()) {
                throw new DomainConflictException(__('retention.journal_missing'), 'ERASURE_JOURNAL_UNAVAILABLE');
            }

            return;
        }
        if ($this->missingPreservations($org) !== []) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }
        if ($this->journal->intents($org, 0, 1000) === []) {
            return;
        }
        $event = $this->database->table('organization_retention_events')->where('organization_id', $org)
            ->where('action', 'release-quarantine')->orderByDesc('version')->first(['evidence']);
        $evidence = $event === null ? null : json_decode((string) $event->evidence, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($evidence) || ! is_string($evidence['journal_head'] ?? null) || ! hash_equals($this->journal->head($org), $evidence['journal_head'])) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }
    }

    /** @return list<array{request_id: string, reference: string}> */
    public function missingPreservations(string $org): array
    {
        $preservations = $this->journal->preservations($org);
        if ($preservations === []) {
            return [];
        }
        $ids = array_column($preservations, 'request_id');
        $known = [];
        foreach ($this->database->table('organization_retention_events')->where('organization_id', $org)->where('action', 'hold')
            ->where(fn (Builder $query) => $query->whereIn('request_id', $ids)->orWhereIn('evidence->source_hold_request_id', $ids))
            ->limit(2001)->get(['request_id', 'reference', 'evidence']) as $event) {
            $known[(string) $event->request_id] = (string) $event->reference;
            if ($event->evidence !== null) {
                $evidence = json_decode((string) $event->evidence, true, 16, JSON_THROW_ON_ERROR);
                if (is_array($evidence) && is_string($evidence['source_hold_request_id'] ?? null)) {
                    $known[$evidence['source_hold_request_id']] = (string) $event->reference;
                }
            }
        }

        return array_values(array_filter($preservations, static fn (array $record): bool => ($known[$record['request_id']] ?? null) !== $record['reference']));
    }
}
