<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\MatchScorePublisher;
use App\Modules\Competition\Application\Validation\MatchScoreInput as Input;
use App\Modules\Competition\Domain\Contracts\MatchCorrectionRepository;
use App\Modules\Competition\Domain\Contracts\MatchLineupRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Contracts\MatchResultRepository;
use App\Modules\Competition\Domain\Contracts\MatchScoreRepository;
use App\Modules\Competition\Domain\Models\MatchGameInstance;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchScoreRules;
use App\Modules\Competition\Domain\Scoring\BestOfScore;
use App\Modules\Competition\Domain\Scoring\PointsScore;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MatchScoreService
{
    public function __construct(private MatchRepository $matches, private MatchScoreRepository $scores, private MatchLineupRepository $lineups,
        private MatchReferenceDirectory $references, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database,
        private BestOfScore $bestOf, private PointsScore $points, private MatchResultRepository $results,
        private MatchCorrectedScores $effective, private MatchCorrectionRepository $corrections,
        private TenantCache $cache, private MatchScorePublisher $publisher) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function index(TenantContext $context, string $match, array $input): array
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $input, 'index');
        $management = (bool) ($values['management'] ?? false);
        if ($management) {
            $this->write($context);
        }

        // Hold the match lock until the summary and page are materialized at the same version.
        return $this->database->transaction(function () use ($context, $match, $management, $values): array {
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, $this->references->viewer($context), $management, true);
            $rules = $this->scores->rules($context->organizationId, $record->id);
            $games = $this->effective->apply($context->organizationId, $record->id, $this->scores->games($context->organizationId, $record->id));

            $latest = $this->corrections->latest($context->organizationId, $record->id);
            $pending = $latest !== null && $latest->recalculation === null;
            $key = MatchCorrectionService::cacheKey($record->id, $record->version, $latest?->id);
            $series = $rules === null || $pending ? null : ($latest === null ? null : $this->cache->get($context, $key));
            if ($rules !== null && ! $pending && $series === null) {
                $series = $this->calculate($record, $rules, $this->facts($games));
                if ($latest !== null) {
                    $this->cache->put($context, $key, $series, 60);
                }
            }
            $page = $this->scores->paginate($context->organizationId, $record->id, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
            $page = new LengthAwarePaginator(
                $this->effective->apply($context->organizationId, $record->id, array_values($page->items())),
                $page->total(), $page->perPage(), $page->currentPage(), ['path' => Paginator::resolveCurrentPath()]);

            return ['recalculation_pending' => $pending, 'score_revision' => $latest?->id, 'result_locked' => $this->results->latest($context->organizationId, $record->id) !== null, 'match_version' => $record->version, 'rules' => $this->rulesProjection($rules),
                'series' => $series, 'games' => $page];
        });
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(TenantContext $context, string $match, array $input, bool $configure, ?string $ip): array
    {
        $this->write($context);
        $values = Input::validate($this->validator, $input, $configure ? 'configure' : 'store');

        return $this->database->transaction(function () use ($context, $match, $values, $configure, $ip): array {
            $this->guard->lock($context);
            $this->write($context);
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true, true);
            $this->effective->assertReady($context->organizationId, $record->id);
            if ($record->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('matches.stale'), 'MATCH_VERSION_STALE');
            }
            if ($this->results->latest($context->organizationId, $record->id) !== null) {
                throw new DomainConflictException(__('match_results.locked'), 'MATCH_RESULT_LOCKED');
            }
            if (! in_array($record->status, ['in_progress', 'awaiting_validation'], true)) {
                throw new DomainConflictException(__('match_scores.frozen'), 'MATCH_SCORE_FROZEN');
            }
            if (mb_strlen(trim($values['reason'])) < 10) {
                throw ValidationException::withMessages(['reason' => __('matches.reason')]);
            }
            if ($record->visibility !== 'private') {
                $this->authorization->authorize($context, 'sports.publish');
                if (! ($values['confirm_sharing'] ?? false)) {
                    throw ValidationException::withMessages(['confirm_sharing' => __('matches.sharing')]);
                }
            }
            $rules = $this->scores->rules($context->organizationId, $record->id);
            if ($configure) {
                if ($rules !== null) {
                    throw new DomainConflictException(__('match_scores.rules_frozen'), 'MATCH_SCORE_RULES_FROZEN');
                }
                $expected = match ($record->format) {
                    'bo1' => 1, 'bo3' => 3, 'bo5' => 5, default => (int) $values['game_limit']
                };
                if ((int) $values['game_limit'] !== $expected) {
                    throw ValidationException::withMessages(['game_limit' => __('match_scores.format_limit')]);
                }
                $version = (string) Str::uuid();
                $this->matches->save($record, ['version' => $version], null);
                $rules = $this->scores->configure(['organization_id' => $context->organizationId, 'match_id' => $record->id, 'match_version' => $version,
                    'format' => $record->format, 'game_limit' => $expected, 'allow_draw' => (bool) $values['allow_draw'], 'reason' => trim($values['reason']), 'actor_user_id' => $context->actorUserId]);
                $game = null;
                $series = $this->calculate($record, $rules, []);
            } else {
                if ($rules === null) {
                    throw new DomainConflictException(__('match_scores.rules_required'), 'MATCH_SCORE_RULES_REQUIRED');
                }
                $games = $this->effective->apply($context->organizationId, $record->id, $this->scores->games($context->organizationId, $record->id));
                if (count($games) >= Input::MAX_GAMES) {
                    throw new DomainConflictException(__('match_scores.limit'), 'MATCH_GAME_LIMIT');
                }
                $facts = $this->facts($games);
                if ($this->calculate($record, $rules, $facts)['complete']) {
                    throw new DomainConflictException(__('match_scores.complete'), 'MATCH_SERIES_COMPLETE');
                }
                $results = $this->results($context, $record, $rules, $values);
                $series = $this->calculate($record, $rules, [...$facts, ['outcome' => $values['outcome'], 'results' => $results]]);
                if ($series['draw'] && ! $rules->allow_draw) {
                    throw ValidationException::withMessages(['results' => __('match_scores.draw_forbidden')]);
                }
                $version = (string) Str::uuid();
                $this->matches->save($record, ['version' => $version], null);
                $game = $this->scores->append(['organization_id' => $context->organizationId, 'match_id' => $record->id, 'match_version' => $version,
                    'number' => count($games) + 1, 'outcome' => $values['outcome'], 'map' => isset($values['map']) ? trim($values['map']) : null,
                    'mode' => isset($values['mode']) ? trim($values['mode']) : null, 'game_patch' => trim($values['game_patch']), 'results' => $results,
                    'reason' => trim($values['reason']), 'actor_user_id' => $context->actorUserId]);
            }
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['match_id' => $record->id, 'match_action' => $configure ? 'score_rules' : 'game', 'match_status' => $record->status, 'version' => $version]));

            $this->publisher->changed($context->organizationId, $record->id);

            return ['score_revision' => $this->corrections->latest($context->organizationId, $record->id)?->id, 'recalculation_pending' => false, 'match_version' => $version, 'rules' => $this->rulesProjection($rules), 'series' => $series, 'game' => $game];
        });
    }

    /** @param array<string,mixed> $values
     * @return list<array{participant_id:string,label:string,score:?int,lineup_revision_id:?string}>
     */
    private function results(TenantContext $context, MatchRecord $record, MatchScoreRules $rules, array $values): array
    {
        $provided = [];
        foreach ($values['results'] as $result) {
            $provided[strtolower($result['participant_id'])] = $result['score'] === null ? null : (int) $result['score'];
        }
        $ids = array_values($record->participants->map(fn (MatchParticipant $p): string => $p->id)->all());
        if ($provided === [] || count($provided) !== count($ids) || array_diff(array_keys($provided), $ids) !== []) {
            throw ValidationException::withMessages(['results' => __('match_scores.participants')]);
        }
        $remake = $values['outcome'] === 'remake';
        foreach ($provided as $score) {
            if (($remake && $score !== null) || (! $remake && $score === null)) {
                throw ValidationException::withMessages(['results' => __('match_scores.score')]);
            }
        }
        if (! $remake) {
            $tied = count(array_keys($provided, max($provided), true)) > 1;
            if (($values['outcome'] === 'draw') !== $tied) {
                throw ValidationException::withMessages(['results' => __('match_scores.outcome')]);
            }
            if ($tied && ! $rules->allow_draw) {
                throw ValidationException::withMessages(['results' => __('match_scores.draw_forbidden')]);
            }
        }
        $current = $this->lineups->current($context->organizationId, $record->id);
        $rows = [];
        foreach ($record->participants as $participant) {
            $rows[] = ['participant_id' => $participant->id, 'label' => $participant->label, 'score' => $provided[$participant->id],
                'lineup_revision_id' => $current[$participant->id]->id ?? null];
        }

        return $rows;
    }

    /** @param list<MatchGameInstance> $games
     * @return list<array{outcome:string,results:list<array{participant_id:string,score:?int}>}>
     */
    private function facts(array $games): array
    {
        return array_map(fn ($game): array => ['outcome' => $game->outcome, 'results' => array_values(array_map(fn (array $r): array => ['participant_id' => $r['participant_id'], 'score' => $r['score']], $game->results))], $games);
    }

    /** @param list<array{outcome:string,results:list<array{participant_id:string,score:?int}>}> $games
     * @return array{complete:bool,played:int,winner_ids:list<string>,standings:list<array{participant_id:string,score:int}>,draw:bool}
     */
    private function calculate(MatchRecord $record, MatchScoreRules $rules, array $games): array
    {
        $strategy = in_array($record->format, ['bo1', 'bo3', 'bo5'], true) ? $this->bestOf : $this->points;
        try {
            return $strategy->calculate(array_values($record->participants->map(fn (MatchParticipant $p): string => $p->id)->all()), $games, $rules->game_limit);
        } catch (\DomainException $exception) {
            throw new DomainConflictException(__('match_scores.complete'), 'MATCH_SERIES_COMPLETE');
        }
    }

    /** @return array{format:string,game_limit:int,allow_draw:bool}|null */
    private function rulesProjection(?MatchScoreRules $rules): ?array
    {
        return $rules === null ? null : ['format' => $rules->format, 'game_limit' => $rules->game_limit, 'allow_draw' => $rules->allow_draw];
    }

    private function write(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.write');
    }
}
