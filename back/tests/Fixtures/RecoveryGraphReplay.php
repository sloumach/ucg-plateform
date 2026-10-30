<?php

namespace Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

/** Reconstruct archived aggregates under normal SQL guards in the owned laboratory.
 * @phpstan-type Record array{table:string,data:array<string,mixed>}
 */
final readonly class RecoveryGraphReplay
{
    public function __construct(private Connection $db) {}

    /** Collapse aggregate internals, retaining every external FK dependency.
     * @param  array<string,Record>  $missing
     * @param  array<string,list<string>>  $dependencies
     * @return list<list<string>>
     */
    public static function batches(array $missing, array $dependencies): array
    {
        $roots = [];
        foreach ($missing as $key => $record) {
            if (($record['table'] === 'contracts' && $record['data']['status'] !== 'draft')
                || ($record['table'] === 'matches' && $record['data']['status'] !== 'proposed')) {
                $roots[$key] = true;
            }
        }
        $owners = [];
        $groups = [];
        foreach ($missing as $key => $record) {
            $owner = $key;
            foreach (['contract_id' => 'contracts:', 'match_id' => 'matches:'] as $field => $prefix) {
                $id = $record['data'][$field] ?? null;
                if (is_string($id) && isset($roots[$prefix.$id])) {
                    $owner = $prefix.$id;
                }
            }
            $owners[$key] = $owner;
            $groups[$owner][] = $key;
            if ($owner === $key && (($record['table'] === 'contract_transitions' && $record['data']['from_status'] !== null)
                || in_array($record['table'], ['contract_document_signatures', 'contract_amendment_approvals', 'contract_approved_copies', 'match_result_events'], true))) {
                throw new RuntimeException('GRAPH_PROTECTED_STATE');
            }
        }
        foreach ($roots as $root => $_) {
            self::history($missing[$root]['data'], array_intersect_key($missing, array_flip($groups[$root])), $missing[$root]['table'] === 'contracts');
        }
        $edges = array_fill_keys(array_keys($groups), []);
        foreach ($dependencies as $key => $parents) {
            foreach ($parents as $parent) {
                if ($owners[$parent] !== $owners[$key]) {
                    $edges[$owners[$key]][$owners[$parent]] = true;
                }
            }
        }
        $ordered = [];
        while ($edges !== []) {
            $ready = array_keys(array_filter($edges, static fn (array $parents): bool => $parents === []));
            if ($ready === []) {
                throw new RuntimeException('GRAPH_CYCLE');
            }
            foreach ($ready as $key) {
                $ordered[] = $groups[$key];
                unset($edges[$key]);
            }
            foreach ($edges as &$parents) {
                $parents = array_diff_key($parents, array_flip($ready));
            }
            unset($parents);
        }

        return $ordered;
    }

    /** @param array<string,Record> $records */
    public function insert(array $records): void
    {
        foreach ($records as $record) {
            if ($record['table'] === 'contracts' && $record['data']['status'] !== 'draft') {
                $this->contract($record['data'], $records);

                return;
            }
            if ($record['table'] === 'matches' && $record['data']['status'] !== 'proposed') {
                $this->match($record['data'], $records);

                return;
            }
        }
        foreach ($records as $record) {
            $this->db->table($record['table'])->insert($record['data']);
        }
    }

    /** @param array<string,mixed> $root
     * @param  array<string,Record>  $records
     * @return non-empty-list<array<string,mixed>>
     */
    private static function history(array $root, array $records, bool $contract): array
    {
        $table = $contract ? 'contract_transitions' : 'match_status_events';
        $version = $contract ? 'contract_version' : 'match_version';
        $events = array_values(array_map(static fn (array $row): array => $row['data'], array_filter($records, static fn (array $row): bool => $row['table'] === $table)));
        $initial = array_values(array_filter($events, static fn (array $row): bool => $row['from_status'] === null));
        if (count($initial) !== 1 || $initial[0]['to_status'] !== ($contract ? 'draft' : 'proposed')) {
            throw new RuntimeException('GRAPH_PROTECTED_STATE');
        }
        $ordered = [$initial[0]];
        $remaining = array_values(array_filter($events, static fn (array $row): bool => $row['from_status'] !== null));
        while ($remaining !== []) {
            $previous = $ordered[array_key_last($ordered)];
            $candidates = array_filter($remaining, static fn (array $row): bool => $row['from_status'] === $previous['to_status']
                && (count($remaining) === 1 || $row[$version] !== $root['version']));
            usort($candidates, static fn (array $a, array $b): int => [$a[$contract ? 'occurred_at' : 'created_at'], $a['id']] <=> [$b[$contract ? 'occurred_at' : 'created_at'], $b['id']]);
            if ($candidates === []) {
                throw new RuntimeException('GRAPH_PROTECTED_STATE');
            }
            $next = $candidates[0];
            $ordered[] = $next;
            $remaining = array_values(array_filter($remaining, static fn (array $row): bool => $row['id'] !== $next['id']));
        }
        $last = $ordered[array_key_last($ordered)];
        if ($last['to_status'] !== $root['status'] || (! $contract && $last[$version] !== $root['version'])) {
            throw new RuntimeException('GRAPH_PROTECTED_STATE');
        }
        if ($contract && $root['approved_by'] !== null && $root['approved_by'] === $root['prepared_by']) {
            throw new RuntimeException('GRAPH_PROTECTED_STATE');
        }
        if (! $contract) {
            foreach ($ordered as $event) {
                if (in_array($event['to_status'], ['awaiting_validation', 'validated', 'forfeited', 'disputed'], true)) {
                    $proofs = array_filter($records, static fn (array $row): bool => $row['table'] === 'match_result_events'
                        && $row['data']['match_version'] === $event['match_version'] && $row['data']['from_status'] === $event['from_status']
                        && $row['data']['to_status'] === $event['to_status'] && $row['data']['actor_user_id'] === $event['actor_user_id']);
                    if (count($proofs) !== 1) {
                        throw new RuntimeException('GRAPH_PROTECTED_STATE');
                    }
                }
            }
        }

        return $ordered;
    }

    /** @param array<string,mixed> $root
     * @param  array<string,Record>  $records
     */
    private function contract(array $root, array $records): void
    {
        $history = self::history($root, $records, true);
        $initial = $root;
        $initial['status'] = 'draft';
        $initial['version'] = $history[0]['contract_version'];
        foreach (['approved_by', 'approved_at', 'signed_on', 'signature_reference', 'signature_recorded_at', 'signature_recorded_by', 'terminated_on'] as $field) {
            $initial[$field] = null;
        }
        $this->db->table('contracts')->insert($initial);
        $pending = array_filter($records, static fn (array $row): bool => ! in_array($row['table'], ['contracts', 'contract_transitions'], true));
        foreach ($history as $index => $event) {
            if ($index !== 0) {
                $this->drain($pending);
                $patch = ['status' => $event['to_status'], 'version' => $event['contract_version']];
                if ($event['to_status'] === 'approved') {
                    $patch += ['approved_by' => $event['actor_user_id'], 'approved_at' => $root['approved_by'] === $event['actor_user_id'] ? $root['approved_at'] : $event['occurred_at']];
                } elseif (in_array($event['to_status'], ['draft', 'in_review'], true)) {
                    $patch += ['approved_by' => null, 'approved_at' => null];
                }
                if ($event['to_status'] === 'signed') {
                    $patch += array_intersect_key($root, array_flip(['signed_on', 'signature_reference', 'signature_recorded_at', 'signature_recorded_by']));
                }
                if ($event['to_status'] === 'terminated') {
                    $patch['terminated_on'] = $root['terminated_on'];
                }
                if ($index === array_key_last($history)) {
                    $patch = $root;
                    $patch['version'] = $event['contract_version'];
                }
                $this->db->table('contracts')->where('organization_id', $root['organization_id'])->where('id', $root['id'])->update($patch);
            }
            $this->db->table('contract_transitions')->insert($event);
            $this->drain($pending);
        }
        if ($pending !== []) {
            throw new RuntimeException('GRAPH_REPLAY_INCOMPLETE');
        }
        if ($history[array_key_last($history)]['contract_version'] !== $root['version']) {
            $this->db->table('contracts')->where('organization_id', $root['organization_id'])->where('id', $root['id'])->update($root);
        }
    }

    /** @param array<string,mixed> $root
     * @param  array<string,Record>  $records
     */
    private function match(array $root, array $records): void
    {
        $history = self::history($root, $records, false);
        $initial = $root;
        $initial['status'] = 'proposed';
        $initial['version'] = $history[0]['match_version'];
        $this->db->table('matches')->insert($initial);
        $pending = array_filter($records, static fn (array $row): bool => ! in_array($row['table'], ['matches', 'match_status_events', 'match_result_events'], true));
        $results = array_values(array_map(static fn (array $row): array => $row['data'], array_filter($records, static fn (array $row): bool => $row['table'] === 'match_result_events')));
        usort($results, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        foreach ($history as $index => $event) {
            if ($index !== 0) {
                $this->drain($pending, $root);
                $proof = array_values(array_filter($results, static fn (array $row): bool => $row['match_version'] === $event['match_version']));
                if ($proof !== []) {
                    $this->db->table('match_result_events')->insert($proof[0]);
                    $results = array_values(array_filter($results, static fn (array $row): bool => $row['id'] !== $proof[0]['id']));
                }
                $this->db->table('matches')->where('organization_id', $root['organization_id'])->where('id', $root['id'])
                    ->update(['status' => $event['to_status'], 'version' => $event['match_version']]);
            }
            $this->db->table('match_status_events')->insert($event);
            $this->drain($pending, $root);
        }
        if ($pending !== [] || $results !== []) {
            throw new RuntimeException('GRAPH_REPLAY_INCOMPLETE');
        }
    }

    /** Failed readiness attempts roll back to a savepoint, with all guards active.
     * @param  array<string,Record>  $pending
     * @param  array<string,mixed>|null  $match
     */
    private function drain(array &$pending, ?array $match = null): void
    {
        do {
            $progress = false;
            foreach ($pending as $key => $record) {
                try {
                    $this->db->transaction(function () use ($record, $match): void {
                        $row = $record['data'];
                        if ($match !== null && isset($row['match_version'])) {
                            $this->db->table('matches')->where('organization_id', $match['organization_id'])->where('id', $match['id'])->update(['version' => $row['match_version']]);
                        }
                        if ($record['table'] === 'contract_obligations' && $row['status'] !== 'pending') {
                            $initial = $row;
                            $initial['status'] = 'pending';
                            $this->db->table($record['table'])->insert($initial);
                            $this->db->table($record['table'])->where('organization_id', $row['organization_id'])->where('id', $row['id'])->update($row);
                        } else {
                            $this->db->table($record['table'])->insert($row);
                        }
                    });
                    unset($pending[$key]);
                    $progress = true;
                } catch (QueryException $exception) {
                    if (! in_array($exception->getCode(), ['23514', '23503', 'P0001'], true)) {
                        throw $exception;
                    }
                }
            }
        } while ($progress && $pending !== []);
    }
}
