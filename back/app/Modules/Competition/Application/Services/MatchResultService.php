<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\MatchScorePublisher;
use App\Modules\Competition\Application\Validation\MatchResultInput as Input;
use App\Modules\Competition\Domain\Contracts\MatchCorrectionRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Contracts\MatchResultRepository;
use App\Modules\Competition\Domain\Contracts\MatchScoreRepository;
use App\Modules\Competition\Domain\MatchResultWorkflow;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchResultEvent;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MatchResultService
{
    public function __construct(private MatchRepository $matches, private MatchResultRepository $results, private MatchScoreRepository $scores,
        private MatchScoreService $scoreService, private MatchReferenceDirectory $references, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database,
        private MatchCorrectedScores $effective, private MatchCorrectionRepository $corrections, private MatchScorePublisher $publisher) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function index(TenantContext $context, string $match, array $input): array
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $input, 'index');
        $management = (bool) ($values['management'] ?? false);
        if ($management) {
            $this->authorize($context, 'submit');
        }

        return $this->database->transaction(function () use ($context, $match, $values, $management): array {
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, $this->references->viewer($context), $management, true);

            return [...$this->project($context, $record), 'history' => $this->results->history($context->organizationId, $record->id, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10))];
        });
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function change(TenantContext $context, string $match, array $input, string $action, ?string $ip = null): array
    {
        if (! in_array($action, ['submit', 'approve', 'dispute'], true)) {
            throw new \InvalidArgumentException('Unknown result action.');
        }
        $this->authorize($context, $action);
        $values = Input::validate($this->validator, $input, $action);

        return $this->database->transaction(function () use ($context, $match, $values, $action, $ip): array {
            $this->guard->lock($context);
            $this->authorize($context, $action);
            $management = $this->authorization->allows($context, 'sports.write');
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, $this->references->viewer($context), $management, true);
            $this->effective->assertReady($context->organizationId, $record->id);
            if ($record->version !== strtolower($values['version'])) {
                $this->conflict('matches.stale', 'MATCH_VERSION_STALE');
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
            $latest = $this->results->latest($context->organizationId, $record->id);
            if (($latest->number ?? 0) >= Input::MAX_EVENTS) {
                $this->conflict('match_results.limit', 'MATCH_RESULT_LIMIT');
            }
            $attributes = ['submission_id' => null, 'proof_reference' => null, 'snapshot' => null, 'contributors' => null];
            if ($action === 'submit') {
                $correction = $this->corrections->latest($context->organizationId, $record->id);
                if (($correction?->id) !== (isset($values['score_revision']) ? strtolower($values['score_revision']) : null)) {
                    $this->conflict('match_corrections.revision', 'MATCH_SCORE_REVISION_STALE');
                }
                $target = MatchResultWorkflow::target($action, $record->status, $latest?->action, $values['outcome']);
                if ($target === null) {
                    $this->conflict('match_results.transition', 'MATCH_RESULT_TRANSITION_INVALID');
                }
                if (mb_strlen(trim($values['proof_reference'])) < 10) {
                    throw ValidationException::withMessages(['proof_reference' => __('match_results.proof')]);
                }
                [$snapshot, $contributors] = $this->snapshot($context, $record, $values);
                $attributes = ['submission_id' => null, 'proof_reference' => trim($values['proof_reference']), 'snapshot' => $snapshot, 'contributors' => $contributors];
                $outcome = $values['outcome'];
            } else {
                if ($latest === null || ! in_array($latest->action, $action === 'approve' ? ['submit'] : ['submit', 'approve'], true)) {
                    $this->conflict('match_results.transition', 'MATCH_RESULT_TRANSITION_INVALID');
                }
                $submission = $this->results->submission($latest);
                if ($submission->id !== strtolower($values['submission_id'])) {
                    $this->conflict('match_results.stale_submission', 'MATCH_RESULT_SUBMISSION_STALE');
                }
                if ($action === 'approve' && ! $this->independent($context, $submission)) {
                    $this->conflict('match_results.independent', 'MATCH_RESULT_INDEPENDENT_REQUIRED');
                }
                $attributes['submission_id'] = $submission->id;
                $outcome = $submission->outcome;
                $target = MatchResultWorkflow::target($action, $record->status, $latest->action, $outcome);
                if ($target === null) {
                    $this->conflict('match_results.transition', 'MATCH_RESULT_TRANSITION_INVALID');
                }
            }
            $oldVersion = $record->version;
            $previous = $record->status;
            $version = (string) Str::uuid();
            // Append the authorization of this exact transition before the SQL-protected parent update.
            $this->results->append([...$attributes, 'organization_id' => $context->organizationId, 'match_id' => $record->id,
                'base_version' => $oldVersion, 'match_version' => $version, 'number' => ($latest->number ?? 0) + 1, 'action' => $action,
                'outcome' => $outcome, 'from_status' => $previous, 'to_status' => $target, 'reason' => trim($values['reason']), 'actor_user_id' => $context->actorUserId]);
            $record = $this->matches->save($record, ['status' => $target, 'version' => $version], null);
            if ($previous !== $target) {
                $this->matches->event(['organization_id' => $context->organizationId, 'match_id' => $record->id, 'from_status' => $previous,
                    'to_status' => $target, 'match_version' => $version, 'reason' => trim($values['reason']), 'actor_user_id' => $context->actorUserId]);
            }
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['match_id' => $record->id, 'match_action' => 'result_'.$action, 'match_status' => $target, 'version' => $version]));

            $this->publisher->changed($context->organizationId, $record->id);

            return $this->project($context, $record);
        });
    }

    /** @param array<string,mixed> $values
     * @return array{array<string,mixed>,list<int>} */
    private function snapshot(TenantContext $context, MatchRecord $record, array $values): array
    {
        $card = $this->scoreService->index($context, $record->id, ['management' => true, 'per_page' => 1]);
        $participants = array_values($record->participants->map(fn (MatchParticipant $p): array => ['participant_id' => $p->id, 'label' => $p->label])->all());
        if ($values['outcome'] === 'played') {
            if ($record->status === 'confirmed' || ! ($card['series']['complete'] ?? false)) {
                $this->conflict('match_results.incomplete', 'MATCH_RESULT_INCOMPLETE');
            }
            if (isset($values['winner_participant_id'])) {
                throw ValidationException::withMessages(['winner_participant_id' => __('match_results.winner')]);
            }
            $winners = $card['series']['winner_ids'];
        } else {
            $winner = isset($values['winner_participant_id']) ? strtolower($values['winner_participant_id']) : '';
            if (! in_array($winner, array_column($participants, 'participant_id'), true)) {
                throw ValidationException::withMessages(['winner_participant_id' => __('match_results.winner')]);
            }
            $winners = [$winner];
        }
        $games = $this->scores->games($context->organizationId, $record->id);
        $rules = $this->scores->rules($context->organizationId, $record->id);
        $contributors = [$context->actorUserId, ...$this->corrections->contributors($context->organizationId, $record->id), ...array_map(fn ($game): int => $game->actor_user_id, $games)];
        if ($rules !== null) {
            $contributors[] = (int) $rules->getAttribute('actor_user_id');
        }

        return [['outcome' => $values['outcome'], 'winner_ids' => $winners, 'participants' => $participants, 'series' => $card['series'], 'rules' => $card['rules'],
            'score_revision' => $this->corrections->latest($context->organizationId, $record->id)?->id, 'game_ids' => array_map(fn ($game): string => $game->id, $games)], array_values(array_unique($contributors))];
    }

    /** @return array<string,mixed> */
    private function project(TenantContext $context, MatchRecord $record): array
    {
        $latest = $this->results->latest($context->organizationId, $record->id);
        $submission = $latest === null ? null : $this->results->submission($latest);
        $write = $this->authorization->allows($context, 'sports.write');
        $validate = $this->authorization->allows($context, 'sports.validate');
        $sharing = $record->visibility === 'private' || $this->authorization->allows($context, 'sports.publish');
        $actions = [];
        if ($sharing && ($latest->number ?? 0) < Input::MAX_EVENTS) {
            if ($write && MatchResultWorkflow::target('submit', $record->status, $latest?->action, 'forfeit') !== null) {
                $actions[] = 'submit';
            }
            if ($validate && $latest?->action === 'submit' && $submission !== null && $this->independent($context, $submission)) {
                $actions[] = 'approve';
            }
            if (($write || $validate) && in_array($latest?->action, ['submit', 'approve'], true)) {
                $actions[] = 'dispute';
            }
        }

        $correction = $this->corrections->latest($context->organizationId, $record->id);
        $pending = $correction !== null && $correction->recalculation === null;
        if ($pending) {
            $actions = [];
        }

        return ['score_revision' => $correction?->id, 'recalculation_pending' => $pending, 'match_version' => $record->version, 'status' => $record->status, 'locked' => $latest !== null,
            'current' => $latest, 'submission' => $submission, 'allowed_actions' => $actions];
    }

    private function independent(TenantContext $context, MatchResultEvent $submission): bool
    {
        return $context->actorUserId !== $submission->actor_user_id && ! in_array($context->actorUserId, $submission->contributors ?? [], true);
    }

    private function authorize(TenantContext $context, string $action): void
    {
        $this->authorization->authorize($context, 'sports.read');
        if ($action === 'approve') {
            $this->authorization->authorize($context, 'sports.validate');
        } elseif ($action === 'submit' || ! $this->authorization->allows($context, 'sports.validate')) {
            $this->authorization->authorize($context, 'sports.write');
        }
    }

    private function conflict(string $message, string $code): never
    {
        throw new DomainConflictException(__($message), $code);
    }
}
