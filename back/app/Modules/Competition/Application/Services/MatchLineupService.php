<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Validation\MatchLineupInput as Input;
use App\Modules\Competition\Domain\Contracts\MatchLineupRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Models\MatchLineupRevision;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Teams\Application\Contracts\MatchLineups;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MatchLineupService
{
    public function __construct(private MatchRepository $matches, private MatchLineupRepository $records, private MatchLineups $lineups,
        private MatchReferenceDirectory $references, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database, private MatchCorrectedScores $effective) {}

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,MatchLineupRevision>
     */
    public function history(TenantContext $context, string $match, string $participant, array $input): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $input, 'index');
        $management = (bool) ($values['management'] ?? false);
        if ($management) {
            $this->write($context);
        }
        $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, $this->references->viewer($context), $management);
        $this->participant($record, $participant);

        return $this->records->history($context->organizationId, $record->id, strtolower($participant), (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}>
     */
    public function choices(TenantContext $context, string $match, string $participant, array $input): LengthAwarePaginator
    {
        $this->write($context);
        $values = Input::validate($this->validator, $input, 'choices');
        $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true);
        $team = $this->participant($record, $participant);

        return $this->lineups->choices($context, $team->team_id ?? throw new \LogicException('Missing participant team.'), (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input */
    public function change(TenantContext $context, string $match, string $participant, array $input, bool $substitution, ?string $ip): MatchLineupRevision
    {
        $this->write($context);
        $values = Input::validate($this->validator, $input, $substitution ? 'substitute' : 'store');

        return $this->database->transaction(function () use ($context, $match, $participant, $values, $substitution, $ip): MatchLineupRevision {
            $this->guard->lock($context);
            $this->write($context);
            $record = $this->matches->find($context->organizationId, $match, $context->actorUserId, ['person_id' => null, 'team_ids' => []], true, true);
            $this->effective->assertReady($context->organizationId, $record->id);
            if ($record->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('matches.stale'), 'MATCH_VERSION_STALE');
            }
            $team = $this->participant($record, $participant);
            if (! in_array($record->status, $substitution ? ['proposed', 'confirmed', 'in_progress'] : ['proposed', 'confirmed'], true)) {
                throw new DomainConflictException(__('match_lineups.frozen'), 'MATCH_LINEUP_FROZEN');
            }
            if (mb_strlen(trim($values['reason'])) < 10) {
                throw ValidationException::withMessages(['reason' => __('matches.reason')]);
            }
            if ($this->records->count($context->organizationId, $record->id) >= Input::MAX_REVISIONS) {
                throw new DomainConflictException(__('match_lineups.limit'), 'MATCH_LINEUP_LIMIT');
            }
            $previous = $this->records->latest($context->organizationId, $record->id, $team->id);
            if ($substitution) {
                $snapshot = $previous->snapshot ?? throw new DomainConflictException(__('match_lineups.required'), 'MATCH_LINEUP_REQUIRED');
                $snapshot = $this->substitute($snapshot, strtolower($values['out_person_id']), strtolower($values['in_person_id']));
            } else {
                if ($record->visibility !== 'private') {
                    $this->authorization->authorize($context, 'sports.publish');
                    if (! ($values['confirm_sharing'] ?? false)) {
                        throw ValidationException::withMessages(['confirm_sharing' => __('matches.sharing')]);
                    }
                }
                $snapshot = $this->lineups->snapshot($context, $team->team_id ?? throw new \LogicException('Missing participant team.'), strtolower($values['lineup_id']), $record->game_id);
                if ($previous !== null && $previous->snapshot === $snapshot) {
                    throw new DomainConflictException(__('match_lineups.unchanged'), 'MATCH_LINEUP_UNCHANGED');
                }
            }
            $assessment = $this->lineups->assess($context, $snapshot, $this->effectiveAt($record));
            $version = (string) Str::uuid();
            $this->matches->save($record, ['version' => $version], null);
            $revision = $this->records->append(['organization_id' => $context->organizationId, 'match_id' => $record->id, 'participant_id' => $team->id,
                'match_version' => $version, 'number' => ($previous->number ?? 0) + 1, 'action' => $substitution ? 'substitute' : 'assign',
                'phase' => $record->status, 'reason' => trim($values['reason']), 'snapshot' => $snapshot, 'assessment' => $assessment, 'actor_user_id' => $context->actorUserId]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['match_id' => $record->id, 'match_action' => 'lineup', 'match_status' => $record->status, 'version' => $version]));

            return $revision;
        });
    }

    public function assertParticipantsEditable(MatchRecord $record): void
    {
        if ($this->records->count($record->organization_id, $record->id) > 0) {
            throw new DomainConflictException(__('match_lineups.retained'), 'MATCH_LINEUP_PARTICIPANTS_RETAINED');
        }
    }

    public function assertReady(TenantContext $context, MatchRecord $record): void
    {
        $current = $this->records->current($context->organizationId, $record->id);
        $seen = [];
        foreach ($record->participants as $participant) {
            if ($participant->kind === 'person') {
                $seen[] = $participant->person_id;
            }
        }
        foreach ($record->participants as $participant) {
            if ($participant->kind !== 'team') {
                continue;
            }
            $snapshot = $current[$participant->id]->snapshot
                ?? throw new DomainConflictException(__('match_lineups.required'), 'MATCH_LINEUP_REQUIRED');
            $ids = array_column($snapshot['participants'], 'person_id');
            if (array_intersect($seen, $ids) !== []) {
                throw new DomainConflictException(__('match_lineups.duplicate'), 'MATCH_LINEUP_DUPLICATE_PERSON');
            }
            $seen = [...$seen, ...$ids];
            $this->lineups->assess($context, $snapshot, $this->effectiveAt($record));
        }
    }

    private function effectiveAt(MatchRecord $record): CarbonImmutable
    {
        return $record->status === 'in_progress' ? CarbonImmutable::now()->max($record->starts_at) : $record->starts_at;
    }

    /** @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function substitute(array $snapshot, string $out, string $in): array
    {
        $entries = $snapshot['participants'];
        $outIndex = array_search($out, array_column($entries, 'person_id'), true);
        $inIndex = array_search($in, array_column($entries, 'person_id'), true);
        if ($outIndex === false || $inIndex === false || $entries[$outIndex]['slot_key'] !== 'starter' || $entries[$inIndex]['slot_key'] !== 'substitute') {
            throw ValidationException::withMessages(['in_person_id' => __('match_lineups.substitution')]);
        }
        $entries[$outIndex]['slot_key'] = 'substitute';
        $entries[$inIndex]['slot_key'] = 'starter';

        return [...$snapshot, 'participants' => $entries];
    }

    private function participant(MatchRecord $record, string $id): MatchParticipant
    {
        return $record->participants->first(fn ($p): bool => $p->id === strtolower($id) && $p->kind === 'team')
            ?? throw (new ModelNotFoundException)->setModel(MatchParticipant::class);
    }

    private function write(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.write');
    }
}
