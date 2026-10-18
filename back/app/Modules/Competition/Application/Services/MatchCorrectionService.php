<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\MatchRecalculationQueue;
use App\Modules\Competition\Application\Validation\MatchCorrectionInput as Input;
use App\Modules\Competition\Domain\Contracts\MatchCorrectionRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Contracts\MatchResultRepository;
use App\Modules\Competition\Domain\Contracts\MatchScoreRepository;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchScoreCorrection;
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
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

/** @phpstan-import-type CorrectedGame from MatchScoreCorrection */
final readonly class MatchCorrectionService
{
    public function __construct(private MatchRepository $matches, private MatchCorrectionRepository $corrections,
        private MatchScoreRepository $scores, private MatchResultRepository $results, private MatchResultService $resultService,
        private MatchCorrectedScores $effective, private MatchReferenceDirectory $references, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private TenantCache $cache,
        private Factory $validator, private Connection $database, private MatchRecalculationQueue $queue, private LoggerInterface $logger,
        private BestOfScore $bestOf, private PointsScore $points) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function index(TenantContext $context, string $match, array $input): array
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $input, 'index');
        $management = (bool) ($values['management'] ?? false);
        if ($management) {
            $this->authorization->authorize($context, 'sports.write');
        }

        return $this->database->transaction(function () use ($context, $match, $values, $management): array {
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, $this->references->viewer($context), $management, true);
            $latest = $this->corrections->latest($context->organizationId, $record->id);

            return ['history' => $this->corrections->history($context->organizationId, $record->id, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10)),
                'match_version' => $record->version, 'score_revision' => $latest?->id, 'pending' => $latest !== null && $latest->recalculation === null,
                'can_correct' => in_array($record->status, ['in_progress', 'awaiting_validation', 'validated', 'forfeited', 'disputed'], true) && $this->authorization->allows($context, 'sports.write') && $this->authorization->allows($context, 'sports.correct')
                    && ($record->visibility === 'private' || $this->authorization->allows($context, 'sports.publish'))];
        });
    }

    /** @param array<string,mixed> $input */
    public function request(TenantContext $context, string $match, array $input, ?string $ip): MatchScoreCorrection
    {
        $this->authorize($context);
        $values = Input::validate($this->validator, $input, 'store');
        $fingerprint = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($context, $match, $values, $fingerprint, $ip): MatchScoreCorrection {
            $this->guard->lock($context);
            $this->authorize($context);
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true, true);
            $this->sharing($context, $record, $values);
            $existing = $this->corrections->byKey($context->organizationId, $record->id, strtolower($values['idempotency_key']));
            if ($existing !== null) {
                if ($existing->actor_user_id !== $context->actorUserId || ! hash_equals($existing->fingerprint, $fingerprint)) {
                    $this->conflict('key', 'MATCH_CORRECTION_KEY_REUSED');
                }

                return $existing;
            }
            $this->async();
            $latest = $this->corrections->latest($context->organizationId, $record->id);
            $this->effective->assertReady($context->organizationId, $record->id);
            if ($record->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('matches.stale'), 'MATCH_VERSION_STALE');
            }
            if (($latest?->id) !== ($values['score_revision'] === null ? null : strtolower($values['score_revision']))) {
                $this->conflict('revision', 'MATCH_SCORE_REVISION_STALE');
            }
            if (($latest->number ?? 0) >= Input::MAX_CORRECTIONS) {
                $this->conflict('limit', 'MATCH_CORRECTION_LIMIT');
            }
            if (! in_array($record->status, ['in_progress', 'awaiting_validation', 'validated', 'forfeited', 'disputed'], true)) {
                $this->conflict('phase', 'MATCH_CORRECTION_PHASE');
            }
            if (mb_strlen(trim($values['reason'])) < 10) {
                throw ValidationException::withMessages(['reason' => __('matches.reason')]);
            }
            $games = $this->effective->apply($context->organizationId, $record->id, $this->scores->games($context->organizationId, $record->id));
            $before = array_map(fn ($game): array => ['game_id' => $game->id, 'number' => $game->number, 'outcome' => $game->outcome, 'results' => array_values($game->results)], $games);
            $after = $this->correct($record, $before, $values['games']);
            $previousSeries = $this->calculate($record, $before);
            $series = $this->calculate($record, $after);
            if ($previousSeries['complete'] && ! $series['complete']) {
                throw ValidationException::withMessages(['games' => __('match_corrections.invalid')]);
            }
            $baseVersion = $record->version;
            $this->cache->forget($context, self::cacheKey($record->id, $baseVersion, $latest?->id));
            $result = $this->results->latest($context->organizationId, $record->id);
            if ($result !== null && in_array($result->action, ['submit', 'approve'], true)) {
                $submission = $this->results->submission($result);
                $this->resultService->change($context, $record->id, ['version' => $baseVersion, 'submission_id' => $submission->id, 'reason' => trim($values['reason']),
                    ...($record->visibility === 'private' ? [] : ['confirm_sharing' => true])], 'dispute', $ip);
                $record = $this->matches->find($context->organizationId, $record->id, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true, true);
            } elseif ($result === null) {
                $record = $this->matches->save($record, ['version' => (string) Str::uuid()], null);
            } elseif ($record->status !== 'disputed' || $result->action !== 'dispute') {
                $this->conflict('phase', 'MATCH_CORRECTION_PHASE');
            }
            // A disputed result retains its sporting version; score_revision separately prevents stale corrections/publication.
            $correction = $this->corrections->append(['organization_id' => $context->organizationId, 'match_id' => $record->id, 'base_match_version' => $baseVersion,
                'match_version' => $record->version, 'base_correction_id' => $latest?->id, 'idempotency_key' => strtolower($values['idempotency_key']), 'fingerprint' => $fingerprint,
                'number' => ($latest->number ?? 0) + 1, 'before' => $before, 'after' => $after, 'changed_ids' => array_map(fn ($g): string => strtolower($g['game_id']), $values['games']),
                'reason' => trim($values['reason']), 'actor_user_id' => $context->actorUserId]);
            $this->audit($context, $record->id, $correction->id, 'correction_requested', $ip);
            $this->enqueue($context, $record->id, $correction->id);

            return $correction;
        });
    }

    /** @param array<string,mixed> $input */
    public function retry(TenantContext $context, string $match, string $id, array $input, ?string $ip): MatchScoreCorrection
    {
        $this->authorize($context);
        Input::validate($this->validator, $input, 'retry');
        $this->async();

        return $this->database->transaction(function () use ($context, $match, $id, $ip): MatchScoreCorrection {
            $this->guard->lock($context);
            $this->authorize($context);
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true, true);
            if ($record->visibility !== 'private') {
                $this->authorization->authorize($context, 'sports.publish');
            }
            $correction = $this->corrections->find($context->organizationId, $record->id, $id);
            if ($correction->recalculation === null) {
                $this->audit($context, $record->id, $id, 'correction_retried', $ip);
                $this->enqueue($context, $record->id, $id);
            }

            return $correction;
        });
    }

    public function process(TenantContext $context, string $match, string $id): void
    {
        $this->database->transaction(function () use ($context, $match, $id): void {
            $this->guard->lock($context);
            $this->authorize($context);
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true, true);
            $correction = $this->corrections->find($context->organizationId, $record->id, $id);
            if ($correction->recalculation !== null) {
                return;
            }
            if ($record->visibility !== 'private') {
                $this->authorization->authorize($context, 'sports.publish');
            }
            $latest = $this->corrections->latest($context->organizationId, $record->id);
            if ($latest?->id !== $id || $record->version !== $correction->match_version) {
                $this->conflict('revision', 'MATCH_SCORE_REVISION_STALE');
            }
            $series = $this->calculate($record, $correction->after);
            $this->cache->forget($context, self::cacheKey($record->id, $record->version, $id));
            $this->corrections->complete(['organization_id' => $context->organizationId, 'match_id' => $record->id, 'correction_id' => $id, 'series' => $series, 'actor_user_id' => $context->actorUserId]);
            $this->audit($context, $record->id, $id, 'correction_applied', null);
        });
    }

    public static function cacheKey(string $match, string $version, ?string $revision): string
    {
        return 'match.score/'.$match.'/'.$version.'/'.($revision ?? 'original');
    }

    /** @param list<CorrectedGame> $games
     * @param list<array<string,mixed>> $changes
     * @return list<CorrectedGame> */
    private function correct(MatchRecord $record, array $games, array $changes): array
    {
        $indexed = array_column($games, null, 'game_id');
        $changed = false;
        $ids = $record->participants->pluck('id')->all();
        foreach ($changes as $change) {
            $id = strtolower($change['game_id']);
            if (! isset($indexed[$id])) {
                throw ValidationException::withMessages(['games' => __('match_corrections.invalid')]);
            }
            if (($change['outcome'] === 'remake') !== ($indexed[$id]['outcome'] === 'remake')) {
                throw ValidationException::withMessages(['games' => __('match_corrections.invalid')]);
            }
            $scores = [];
            foreach ($change['results'] as $row) {
                $participant = strtolower($row['participant_id']);
                if (array_key_exists($participant, $scores)) {
                    throw ValidationException::withMessages(['games' => __('match_scores.participants')]);
                }
                $scores[$participant] = $row['score'] === null ? null : (int) $row['score'];
            }
            if ($scores === [] || count($scores) !== count($ids) || array_diff(array_keys($scores), $ids) !== []) {
                throw ValidationException::withMessages(['games' => __('match_scores.participants')]);
            }
            $remake = $change['outcome'] === 'remake';
            foreach ($scores as $score) {
                if (($remake && $score !== null) || (! $remake && $score === null)) {
                    throw ValidationException::withMessages(['games' => __('match_scores.score')]);
                }
            }
            if (! $remake) {
                $tied = count(array_keys($scores, max($scores), true)) > 1;
                if (($change['outcome'] === 'draw') !== $tied) {
                    throw ValidationException::withMessages(['games' => __('match_scores.outcome')]);
                }
            }
            $next = ['game_id' => $id, 'number' => $indexed[$id]['number'], 'outcome' => $change['outcome'], 'results' => array_map(fn ($row): array => [...$row, 'score' => $scores[$row['participant_id']]], $indexed[$id]['results'])];
            $changed = $changed || $next !== $indexed[$id];
            $indexed[$id] = $next;
        }
        if (! $changed) {
            throw ValidationException::withMessages(['games' => __('match_corrections.unchanged')]);
        }

        return array_values($indexed);
    }

    /** @param list<CorrectedGame> $games
     * @return array{complete:bool,played:int,winner_ids:list<string>,standings:list<array{participant_id:string,score:int}>,draw:bool} */
    private function calculate(MatchRecord $record, array $games): array
    {
        $rules = $this->scores->rules($record->organization_id, $record->id);
        if ($rules === null || $games === []) {
            throw ValidationException::withMessages(['games' => __('match_corrections.invalid')]);
        }
        $facts = array_map(fn ($g): array => ['outcome' => $g['outcome'], 'results' => array_map(fn ($r): array => ['participant_id' => $r['participant_id'], 'score' => $r['score']], $g['results'])], $games);
        if (! $rules->allow_draw && in_array('draw', array_column($facts, 'outcome'), true)) {
            throw ValidationException::withMessages(['games' => __('match_scores.draw_forbidden')]);
        }
        try {
            $series = (in_array($record->format, ['bo1', 'bo3', 'bo5'], true) ? $this->bestOf : $this->points)->calculate(array_values($record->participants->pluck('id')->all()), $facts, $rules->game_limit);
        } catch (\DomainException) {
            throw ValidationException::withMessages(['games' => __('match_corrections.invalid')]);
        }
        if ($series['draw'] && ! $rules->allow_draw) {
            throw ValidationException::withMessages(['games' => __('match_scores.draw_forbidden')]);
        }

        return $series;
    }

    private function authorize(TenantContext $context): void
    {
        foreach (['sports.read', 'sports.write', 'sports.correct'] as $permission) {
            $this->authorization->authorize($context, $permission);
        }
    }

    /** @param array<string,mixed> $values */
    private function sharing(TenantContext $context, MatchRecord $record, array $values): void
    {
        if ($record->visibility !== 'private') {
            $this->authorization->authorize($context, 'sports.publish');
            if (! ($values['confirm_sharing'] ?? false)) {
                throw ValidationException::withMessages(['confirm_sharing' => __('matches.sharing')]);
            }
        }
    }

    private function async(): void
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            $this->conflict('async', 'MATCH_CORRECTION_ASYNC_REQUIRED');
        }
    }

    private function enqueue(TenantContext $context, string $match, string $id): void
    {
        $this->database->afterCommit(function () use ($context, $match, $id): void {
            try {
                $this->queue->dispatch(new JobContext($context->requestId, $context->organizationId, $context->actorUserId), $match, $id);
            } catch (Throwable $exception) {
                $this->logger->warning('match.recalculation.dispatch_failed', ['tenant_id' => $context->organizationId, 'match_id' => $match, 'correction_id' => $id, 'exception_type' => $exception::class]);
            }
        });
    }

    private function audit(TenantContext $context, string $match, string $id, string $action, ?string $ip): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['match_id' => $match, 'match_correction_id' => $id, 'match_action' => $action]));
    }

    private function conflict(string $key, string $code): never
    {
        throw new DomainConflictException(__('match_corrections.'.$key), $code);
    }
}
