<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Validation\CompetitionOutcomeInput as Input;
use App\Modules\Competition\Domain\Contracts\CompetitionOutcomeRepository;
use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\Models\CompetitionOutcomeRevision as Revision;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CompetitionOutcomeService
{
    public function __construct(private CompetitionOutcomeRepository $records, private ExternalCompetitionRepository $competitions, private ExternalCompetitionService $audit, private MatchService $matches, private MatchRealtimeService $results, private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private ConnectionInterface $database, private Factory $validator) {}

    public function authorize(TenantContext $context, string $stream, bool $write = false): void
    {
        abort_unless(in_array($stream, ['sport', 'awards'], true), 404);
        foreach (['sports.read', 'sports.competitions.read', ...($stream === 'awards' ? ['financial.read', ...($write ? ['financial.write'] : [])] : ($write ? ['sports.competitions.write'] : []))] as $p) {
            $this->authorization->authorize($context, $p);
        }
    }

    public function read(TenantContext $context, string $competition, string $stream): ?Revision
    {
        $this->authorize($context, $stream);
        $this->competitions->find($context->organizationId, $competition);

        return $this->records->latest($context->organizationId, $competition, $stream);
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,Revision> */
    public function history(TenantContext $context, string $competition, string $stream, array $input): LengthAwarePaginator
    {
        $this->read($context, $competition, $stream);
        $v = Input::validate($this->validator, $input, 'history', $stream);

        return $this->records->history($context->organizationId, $competition, $stream, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 5));
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,MatchRecord> */
    public function choices(TenantContext $context, string $competition, array $input): LengthAwarePaginator
    {
        $this->authorize($context, 'sport', true);
        $parent = $this->competitions->find($context->organizationId, $competition);
        $v = Input::validate($this->validator, $input, 'choices', 'sport');

        return $this->matches->paginate($context, [...$v, 'game_id' => $parent->game_id]);
    }

    /** @param iterable<Revision> $records
     * @return array<string,MatchRecord> */
    public function visible(TenantContext $context, iterable $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            foreach ($record->links as $link) {
                $ids[] = $link->match_id;
            }
        }

        return $ids === [] ? [] : $this->matches->visibleByIds($context, array_values(array_unique($ids)));
    }

    /** @param array<string,MatchRecord> $visible
     * @return array<string,mixed> */
    public function project(Revision $record, array $visible): array
    {
        return ['version' => $record->id, 'number' => $record->number, 'stream' => $record->stream, 'payload' => $record->payload, 'reason' => $record->reason, 'occurred_at' => $record->created_at->toISOString(),
            'matches' => $record->links->map(fn ($link): array => ['reference_id' => $link->reference_id, 'match' => isset($visible[$link->match_id]) ? self::matchSummary($visible[$link->match_id]) : null])->all()];
    }

    /** @return array<string,mixed> */
    public static function matchSummary(MatchRecord $match): array
    {
        return ['id' => $match->id, 'version' => $match->version, 'title' => $match->title, 'status' => $match->status];
    }

    /** @return array<string,mixed> */
    public function result(TenantContext $context, string $competition, string $reference): array
    {
        $record = $this->read($context, $competition, 'sport');
        $link = $record?->links->firstWhere('reference_id', $reference);
        if ($link === null) {
            throw (new ModelNotFoundException)->setModel(Revision::class);
        }
        // Recheck the ordinary audience, even when the caller also manages other matches.
        $match = $this->matches->find($context, $link->match_id, []);
        $snapshot = $this->results->snapshot($context, $link->match_id, true);
        $approved = ! $snapshot['recalculation_pending'] && $snapshot['outcome'] !== null && in_array($snapshot['status'], ['validated', 'forfeited'], true);

        $series = $approved && $snapshot['outcome'] === 'played' ? $snapshot['series'] : null;
        $winners = $approved ? $match->participants->filter(fn ($p): bool => in_array($p->id, $snapshot['winner_ids'], true))->map(fn ($p): array => ['participant_id' => $p->id, 'label' => $p->label])->values()->all() : [];
        if ($series !== null) {
            $labels = $match->participants->pluck('label', 'id')->all();
            $series['standings'] = array_map(fn (array $standing): array => [...$standing, 'label' => $labels[$standing['participant_id']] ?? ''], $series['standings']);
        }

        return ['reference_id' => $reference, 'status' => $snapshot['status'], 'approved' => $approved, 'outcome' => $approved ? $snapshot['outcome'] : null, 'series' => $series, 'winners' => $winners, 'observed_at' => $snapshot['observed_at']];
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, string $competition, string $stream, array $input, ?string $ip): Revision
    {
        $this->authorize($context, $stream, true);
        $v = Input::validate($this->validator, $input, 'save', $stream);
        if (mb_strlen(trim($v['reason'])) < 10 || trim($v['source']) === '') {
            throw ValidationException::withMessages(['reason' => __('competition_outcomes.reason')]);
        }

        return $this->database->transaction(function () use ($context, $competition, $stream, $v, $ip): Revision {
            $this->guard->lock($context);
            $this->authorize($context, $stream, true);
            $parent = $this->competitions->find($context->organizationId, $competition, true);
            if ($parent->status !== 'active') {
                $this->conflict('archived');
            }
            $old = $this->records->latest($context->organizationId, $competition, $stream);
            if ($parent->version !== strtolower($v['competition_version']) || $old?->id !== ($v['expected_version'] === null ? null : strtolower($v['expected_version']))) {
                $this->conflict('stale');
            }
            if (($old->number ?? 0) >= Input::MAX_REVISIONS) {
                $this->conflict('limit');
            }
            $today = CarbonImmutable::now($context->timezone)->format('Y-m-d');
            if ($v['observed_on'] > $today || ($v['received_on'] ?? null) > $today) {
                throw ValidationException::withMessages(['observed_on' => __('competition_outcomes.future')]);
            }
            $payload = ['source' => trim($v['source']), 'source_url' => $v['source_url'], 'observed_on' => $v['observed_on'], 'notes' => $v['notes'] === null ? null : trim($v['notes'])];
            $links = [];
            if ($stream === 'awards') {
                if (($v['received_minor'] === null) !== ($v['received_on'] === null)) {
                    throw ValidationException::withMessages(['received_on' => __('competition_outcomes.receipt')]);
                }
                $payload = [...$payload, 'currency' => $v['currency'], 'announced_minor' => $v['announced_minor'], 'received_minor' => $v['received_minor'], 'received_on' => $v['received_on']];
            } else {
                if ($v['place'] !== null && $v['field_size'] !== null && $v['place'] > $v['field_size']) {
                    throw ValidationException::withMessages(['place' => __('competition_outcomes.place')]);
                }
                $payload = [...$payload, 'place' => $v['place'], 'field_size' => $v['field_size']];
                $known = $old?->links->keyBy('reference_id');
                foreach ($v['matches'] as $i => $entry) {
                    if ($entry['reference_id'] !== null) {
                        $existing = $known?->get(strtolower($entry['reference_id']));
                        if ($existing === null || $entry['match_id'] !== null || $entry['match_version'] !== null) {
                            throw (new ModelNotFoundException)->setModel(Revision::class);
                        }
                        $links[] = ['reference_id' => $existing->reference_id, 'match_id' => $existing->match_id];
                    } else {
                        if ($entry['match_id'] === null || $entry['match_version'] === null) {
                            throw ValidationException::withMessages(["matches.{$i}.match_id" => __('competition_outcomes.match')]);
                        }
                        $match = $this->matches->find($context, strtolower($entry['match_id']), []);
                        if ($match->game_id !== $parent->game_id) {
                            throw (new ModelNotFoundException)->setModel(MatchRecord::class);
                        }
                        if ($match->version !== strtolower($entry['match_version'])) {
                            $this->conflict('stale');
                        }
                        $links[] = ['reference_id' => (string) Str::uuid(), 'match_id' => $match->id];
                    }
                }
                if (count(array_unique(array_column($links, 'match_id'))) !== count($links)) {
                    throw ValidationException::withMessages(['matches' => __('competition_outcomes.duplicate')]);
                }
            }
            $before = $old?->links->map(fn ($l): string => $l->match_id)->all() ?? [];
            $after = array_column($links, 'match_id');
            sort($before);
            sort($after);
            if ($old !== null && $old->payload === $payload && $before === $after) {
                throw ValidationException::withMessages(['record' => __('external_competitions.empty')]);
            }
            $row = $this->records->append(['id' => (string) Str::uuid(), 'organization_id' => $context->organizationId, 'competition_id' => $competition, 'stream' => $stream, 'number' => ($old->number ?? 0) + 1, 'payload' => $payload, 'reason' => trim($v['reason']), 'actor_user_id' => $context->actorUserId], $links);
            $this->audit->journal($context, $competition, null, $stream === 'sport' ? 'outcomes_updated' : 'awards_updated', $row->id, $ip);

            return $row;
        });
    }

    private function conflict(string $key): never
    {
        throw new DomainConflictException(__('competition_outcomes.'.$key), 'COMPETITION_OUTCOME_'.strtoupper($key));
    }
}
