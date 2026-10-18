<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Validation\MatchInput;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\MatchWorkflow;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchStatusEvent;
use App\Modules\Scheduling\Application\Contracts\MatchSchedule;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MatchService
{
    public function __construct(private MatchRepository $records, private MatchReferenceDirectory $references,
        private MatchSchedule $schedule, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database, private MatchLineupService $lineups, private MatchCorrectedScores $effective) {}

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,MatchRecord>
     */
    public function paginate(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = MatchInput::validate($this->validator, $input, 'index');

        return $this->records->paginate($context->organizationId, $context->actorUserId, $this->references->viewer($context), $values, $this->management($context, $values));
    }

    /** @param array<string,mixed> $input */
    public function find(TenantContext $context, string $id, array $input): MatchRecord
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = MatchInput::validate($this->validator, $input, 'show');

        return $this->records->find($context->organizationId, $id, $context->actorUserId, $this->references->viewer($context), $this->management($context, $values));
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,MatchStatusEvent>
     */
    public function history(TenantContext $context, string $id, array $input): LengthAwarePaginator
    {
        $values = MatchInput::validate($this->validator, $input, 'history');
        $this->find($context, $id, array_intersect_key($values, ['management' => true]));

        return $this->records->history($context->organizationId, $id, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}>
     */
    public function references(TenantContext $context, string $type, array $input): LengthAwarePaginator
    {
        $this->write($context);
        $values = MatchInput::validate($this->validator, $input, 'references');

        return $this->references->choices($context, $type, isset($values['game_id']) ? strtolower($values['game_id']) : null, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ip = null): MatchRecord
    {
        $this->write($context);
        $values = MatchInput::validate($this->validator, $input, $id === null ? 'store' : 'update');
        if ($id !== null && array_diff(array_keys($values), ['version', 'confirm_sharing']) === []) {
            throw ValidationException::withMessages(['record' => __('matches.empty')]);
        }

        return $this->database->transaction(function () use ($context, $values, $id, $ip): MatchRecord {
            $this->guard->lock($context);
            $this->write($context);
            $record = $id === null ? null : $this->locked($context, $id, $values['version']);
            if ($record !== null && $record->status !== 'proposed') {
                throw new DomainConflictException(__('matches.frozen'), 'MATCH_NOT_EDITABLE');
            }
            if ($record !== null && isset($values['participants'])) {
                $this->lineups->assertParticipantsEditable($record);
            }
            $game = $this->references->game($context, $record === null ? strtolower($values['game_id']) : $record->game_id);
            $attributes = array_intersect_key($values, array_flip(['title', 'kind', 'format', 'visibility']));
            if (isset($attributes['title'])) {
                $attributes['title'] = trim($attributes['title']);
            }
            $attributes += $record === null ? ['organization_id' => $context->organizationId, 'game_id' => $game['id'], 'game_key' => $game['key'],
                'game_name' => $game['name'], 'created_by' => $context->actorUserId, 'status' => 'proposed', 'visibility' => 'private'] : [];
            if ($record === null || array_intersect(array_keys($values), ['starts_at', 'ends_at', 'origin_timezone']) !== []) {
                foreach (['starts_at', 'ends_at', 'origin_timezone'] as $field) {
                    if (! isset($values[$field])) {
                        throw ValidationException::withMessages([$field => __('matches.period')]);
                    }
                }
                $attributes += $this->schedule->period($values['starts_at'], $values['ends_at'], $values['origin_timezone']);
            }
            if (isset($values['responsible_person_id'])) {
                $responsible = strtolower($values['responsible_person_id']);
                $snapshot = $this->references->participant($context, 'person', $responsible, $game['id']);
                $attributes += ['responsible_person_id' => $responsible, 'responsible_label' => $snapshot['label']];
            }
            $participants = isset($values['participants']) ? $this->participantInput($values['participants']) : $this->currentParticipants($record);
            $format = $attributes['format'] ?? $record?->format;
            if (! MatchWorkflow::participantCount($format, count($participants))) {
                throw ValidationException::withMessages(['participants' => __('matches.participant_count')]);
            }
            $visibility = $attributes['visibility'] ?? $record?->visibility;
            if ($visibility === 'team' && ! in_array('team', array_column($participants, 'kind'), true)) {
                throw ValidationException::withMessages(['visibility' => __('matches.team_required')]);
            }
            $sharing = $visibility !== 'private' && ($record === null || $visibility !== $record->visibility || isset($values['participants']));
            if ($sharing) {
                $this->authorization->authorize($context, 'sports.publish');
                if (! ($values['confirm_sharing'] ?? false)) {
                    throw ValidationException::withMessages(['confirm_sharing' => __('matches.sharing')]);
                }
                $attributes += ['sharing_authorized_by' => $context->actorUserId, 'sharing_authorized_at' => now()];
            } elseif ($visibility === 'private') {
                $attributes += ['sharing_authorized_by' => null, 'sharing_authorized_at' => null];
            }
            $rows = null;
            if (isset($values['participants'])) {
                $rows = array_map(fn (array $p): array => ['kind' => $p['kind'], $this->column($p['kind']) => $p['source_id'],
                    'label' => $p['label'], 'reference_version' => $p['version']], $this->references->participants($context, $participants, $game['id']));
            }
            $saved = $this->records->save($record, [...$attributes, 'version' => (string) Str::uuid()], $rows);
            if ($record === null) {
                $this->event($context, $saved, null, null);
            }
            $this->audit($context, $saved, $record === null ? 'created' : 'updated', $ip);

            return $saved;
        });
    }

    /** @param array<string,mixed> $input */
    public function transition(TenantContext $context, string $id, array $input, ?string $ip = null): MatchRecord
    {
        $this->write($context);
        $values = MatchInput::validate($this->validator, $input, 'transition');

        return $this->database->transaction(function () use ($context, $id, $values, $ip): MatchRecord {
            $this->guard->lock($context);
            $this->write($context);
            $record = $this->locked($context, $id, $values['version']);
            $previous = $record->status;
            if (! MatchWorkflow::permits($previous, $values['status'])) {
                throw new DomainConflictException(__('matches.transition'), 'MATCH_TRANSITION_INVALID');
            }
            // Outcome statuses must never masquerade as a validated score without its proof workflow.
            if (in_array($previous, MatchWorkflow::RESULT_STATUSES, true) || in_array($values['status'], MatchWorkflow::RESULT_STATUSES, true)) {
                throw new DomainConflictException(__('matches.results_reserved'), 'MATCH_RESULT_WORKFLOW_REQUIRED');
            }
            if (mb_strlen(trim($values['reason'])) < 10) {
                throw ValidationException::withMessages(['reason' => __('matches.reason')]);
            }
            if (in_array($values['status'], ['confirmed', 'in_progress'], true)) {
                $this->references->participant($context, 'person', $record->responsible_person_id, $record->game_id);
                $this->references->participants($context, $this->currentParticipants($record), $record->game_id);
            }
            $saved = $this->records->save($record, ['status' => $values['status'], 'version' => (string) Str::uuid()], null);
            if ($values['status'] === 'in_progress') {
                $this->lineups->assertReady($context, $saved);
            }
            $this->event($context, $saved, $previous, trim($values['reason']));
            $this->audit($context, $saved, 'transition', $ip);

            return $saved;
        });
    }

    private function locked(TenantContext $context, string $id, string $version): MatchRecord
    {
        $record = $this->records->find($context->organizationId, $id, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true, true);
        $this->effective->assertReady($context->organizationId, $record->id);
        if ($record->version !== strtolower($version)) {
            throw new DomainConflictException(__('matches.stale'), 'MATCH_VERSION_STALE');
        }

        return $record;
    }

    /** @param list<array{kind:string,source_id:string}> $participants
     * @return list<array{kind:string,source_id:string}>
     */
    private function participantInput(array $participants): array
    {
        $result = [];
        $seen = [];
        foreach ($participants as $participant) {
            $participant['source_id'] = strtolower($participant['source_id']);
            $key = $participant['kind'].':'.$participant['source_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['participants' => __('matches.duplicate')]);
            }
            $seen[$key] = true;
            $result[] = $participant;
        }

        return $result;
    }

    /** @return list<array{kind:string,source_id:string}> */
    private function currentParticipants(?MatchRecord $record): array
    {
        return $record === null ? [] : array_values($record->participants->map(fn ($p): array => ['kind' => $p->kind, 'source_id' => $p->sourceId()])->all());
    }

    private function column(string $type): string
    {
        return match ($type) {
            'team' => 'team_id', 'person' => 'person_id', 'opponent_snapshot' => 'opponent_snapshot_id', default => throw new \LogicException('Invalid participant kind.')
        };
    }

    /** @param array<string,mixed> $values */
    private function management(TenantContext $context, array $values): bool
    {
        if (! ($values['management'] ?? false)) {
            return false;
        }
        $this->write($context);

        return true;
    }

    private function write(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.write');
    }

    private function event(TenantContext $context, MatchRecord $record, ?string $from, ?string $reason): void
    {
        $this->records->event(['organization_id' => $context->organizationId, 'match_id' => $record->id, 'from_status' => $from,
            'to_status' => $record->status, 'match_version' => $record->version, 'actor_user_id' => $context->actorUserId, 'reason' => $reason]);
    }

    private function audit(TenantContext $context, MatchRecord $record, string $action, ?string $ip): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['match_id' => $record->id, 'match_action' => $action, 'match_status' => $record->status, 'version' => $record->version]));
    }
}
