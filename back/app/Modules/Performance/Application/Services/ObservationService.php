<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\ObservationMatchDirectory;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Performance\Application\Validation\ObservationInput as Input;
use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Contracts\ObservationRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalImportRepository;
use App\Modules\Performance\Domain\Models\ObservationRevision as Revision;
use App\Modules\Performance\Domain\Models\StatisticalObservation as Observation;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Teams\Application\Contracts\MetricGameDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ObservationService
{
    public function __construct(private ObservationRepository $records, private MetricCatalogueRepository $metrics, private MetricCatalogueService $dictionary, private ObservationMatchDirectory $matches, private SportingPeopleDirectory $people, private MatchReferenceDirectory $games, private MetricGameDirectory $catalogue, private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private ConnectionInterface $database, private Factory $validator, private Encrypter $crypt, private StatisticalImportRepository $imports, private StatisticalCalculationInvalidator $calculations) {}

    public function authorize(TenantContext $context, string $operation = 'read'): void
    {
        foreach (['sports.read', 'sports.statistics.read', ...($operation === 'create' ? ['sports.write', 'sports.statistics.write'] : ($operation === 'correct' ? ['sports.statistics.correct'] : []))] as $p) {
            $this->authorization->authorize($context, $p);
        }
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,Observation> */
    public function index(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorize($context);

        $filters = Input::validate($this->validator, $input, 'index');
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => __('observations.period')]);
        }

        return $this->records->paginate($context->organizationId, $this->matches->visibleIds($context), $filters);
    }

    public function find(TenantContext $context, string $id, bool $lock = false): Observation
    {
        $this->authorize($context);

        return $this->records->find($context->organizationId, $id, $this->matches->visibleIds($context), $lock);
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,Revision> */
    public function history(TenantContext $context, string $id, array $input): LengthAwarePaginator
    {
        $this->find($context, $id);
        $v = Input::validate($this->validator, $input, 'history');

        return $this->records->history($context->organizationId, $id, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 5));
    }

    public function version(TenantContext $context, string $id, string $version): Revision
    {
        $this->find($context, $id);

        return $this->records->version($context->organizationId, $id, $version);
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorize($context, 'create');
        $v = Input::validate($this->validator, $input, 'choices');
        $page = (int) ($v['page'] ?? 1);

        return match ($v['kind']) {
            'people' => $this->people->choices($context, $page, 10),
            'matches' => $this->matches->choices($context, strtolower($v['game_id']), $page),
            default => $this->matches->parts($context, strtolower($v['match_id']), $page),
        };
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ip = null): Observation
    {
        $operation = $id === null ? 'create' : 'correct';
        $this->authorize($context, $operation);
        $v = Input::validate($this->validator, $input, $id === null ? 'store' : 'update');
        $values = $this->revisionValues($v);

        return $this->database->transaction(function () use ($context, $id, $ip, $v, $values, $operation): Observation {
            $this->guard->lock($context);
            $this->authorize($context, $operation);
            $record = $id === null ? null : $this->find($context, $id, true);
            $old = $record?->current;
            if ($record !== null && $old?->id !== strtolower($v['version'])) {
                $this->conflict('stale');
            }
            if (($old->number ?? 0) >= Input::MAX_REVISIONS) {
                $this->conflict('limit');
            }
            if ($old !== null && array_reduce(Input::REVISION_FIELDS, fn (bool $same, string $f): bool => $same && $old->getAttribute($f) === $values[$f], true)) {
                throw ValidationException::withMessages(['record' => __('observations.empty')]);
            }
            if ($record === null) {
                // Same global lock as dictionary edits, after the tenant guard, before reading the exact revision.
                $this->catalogue->lock($this->catalogue->version());
                $this->metrics->state(true);
                $attributes = $this->prepare($context, $v);
                $record = $this->records->create([...$attributes, 'acquired_via' => 'manual']);
            }
            $revision = $this->records->append(['id' => (string) Str::uuid(), 'organization_id' => $context->organizationId, 'observation_id' => $record->id, 'number' => ($old->number ?? 0) + 1, 'base_version' => $old?->id, ...$values, 'reason' => trim($v['reason']), 'actor_user_id' => $context->actorUserId, 'request_id' => $context->requestId]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['observation_id' => $record->id, 'observation_action' => $id === null ? 'created' : 'corrected', 'version' => $revision->id]));
            if ($id !== null) {
                $this->calculations->changed($context, $record->id);
            }

            return $this->find($context, $record->id);
        });
    }

    /** @param iterable<Observation> $records
     * @return array<string,string> */
    public function labels(TenantContext $context, iterable $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            $ids[] = $record->person_id;
        }

        return $this->people->pseudonyms($context, array_values(array_unique($ids)));
    }

    /** @param array<string,string> $labels
     * @return array<string,mixed> */
    public function project(Observation $record, array $labels, ?Revision $revision = null): array
    {
        $revision ??= $record->current;

        return ['id' => $record->id, 'metric_id' => $record->metric_id, 'metric_version' => $record->metric_revision_id, 'game_id' => $record->game_id, 'person_id' => $record->person_id, 'person_label' => $labels[$record->person_id] ?? null, 'match_id' => $record->match_id, 'match_game_id' => $record->match_game_id, 'source_kind' => $record->source_kind, 'acquired_via' => $record->acquired_via, 'origin_reference' => $record->origin_reference, 'source_label' => $record->source_label, 'period_start' => $record->period_start, 'period_end' => $record->period_end, 'observed_on' => $record->observed_on, 'sample_size' => $record->sample_size, 'context' => $record->context, 'version' => $revision?->id, 'number' => $revision?->number, 'availability' => $revision?->availability, 'value' => $revision?->value, 'missing_reason' => $revision?->missing_reason, 'notes' => $revision?->notes, 'status' => $revision?->status, 'reason' => $revision?->reason, 'occurred_at' => $revision?->created_at->toISOString()];
    }

    /** Read-only domain validation; caller holds the tenant guard and catalogue locks.
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function draft(TenantContext $context, array $input): array
    {
        $this->authorize($context, 'create');
        $v = Input::validate($this->validator, $input, 'store');
        $values = $this->revisionValues($v);

        return ['observation' => $this->prepare($context, $v), 'revision' => [...$values, 'reason' => trim($v['reason'])]];
    }

    /** Caller holds the tenant guard; receipt and observation commit in the same line transaction.
     * @param  array<string,mixed>  $input
     * @return array{status:string,code:?string}
     */
    public function import(TenantContext $context, array $input, string $importId): array
    {
        return $this->database->transaction(function () use ($context, $input, $importId): array {
            $this->guard->lock($context);
            $this->authorize($context, 'create');
            $this->authorization->authorize($context, 'sports.statistics.import');
            $this->catalogue->lock($this->catalogue->version());
            $this->metrics->state(true);
            try {
                $draft = $this->draft($context, $input);
            } catch (ValidationException|ModelNotFoundException|DomainConflictException) {
                return ['status' => 'rejected', 'code' => 'REFERENCE_CHANGED'];
            }
            $attributes = $draft['observation'];
            $values = $draft['revision'];
            $identity = array_intersect_key($attributes, array_flip(['organization_id', 'metric_id', 'game_id', 'person_id',
                'match_id', 'match_game_id', 'source_kind', 'source_label', 'period_start', 'period_end', 'observed_on']));
            $identity['scope'] = array_intersect_key($attributes['context'], array_flip(['region', 'season', 'map', 'opponent', 'round_number', 'session_label']));
            $identity['references'] = array_intersect_key($attributes['context']['game_context'], array_flip(['mode_id', 'role_id', 'release_id']));
            $key = hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), $this->crypt->getKey());
            $hash = hash_hmac('sha256', json_encode([$attributes['metric_revision_id'], $attributes['sample_size'],
                array_intersect_key($values, array_flip(Input::REVISION_FIELDS))], JSON_THROW_ON_ERROR), $this->crypt->getKey());
            $receipt = $this->imports->receipt($context->organizationId, $key);
            if ($receipt !== null) {
                return hash_equals($receipt->payload_hash, $hash)
                    ? ['status' => 'duplicate', 'code' => 'ALREADY_IMPORTED']
                    : ['status' => 'rejected', 'code' => 'OBSERVATION_CONFLICT'];
            }
            $record = $this->records->create([...$attributes, 'acquired_via' => 'import', 'origin_reference' => 'csv:'.$key]);
            $revision = $this->records->append(['id' => (string) Str::uuid(), 'organization_id' => $context->organizationId,
                'observation_id' => $record->id, 'number' => 1, 'base_version' => null, ...$values,
                'actor_user_id' => $context->actorUserId, 'request_id' => $context->requestId]);
            $this->imports->createReceipt(['organization_id' => $context->organizationId, 'import_id' => $importId,
                'observation_id' => $record->id, 'identity_key' => $key, 'payload_hash' => $hash]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, null, [],
                ['observation_id' => $record->id, 'statistical_import_id' => $importId, 'version' => $revision->id]));

            return ['status' => 'created', 'code' => null];
        });
    }

    /** @param array<string,mixed> $v
     * @return array<string,mixed>
     */
    private function revisionValues(array $v): array
    {
        if (mb_strlen(trim($v['reason'])) < 10) {
            throw ValidationException::withMessages(['reason' => __('observations.reason')]);
        }
        if (($v['availability'] === 'available' && ($v['value'] === null || $v['missing_reason'] !== null)) || ($v['availability'] === 'unavailable' && ($v['value'] !== null || $v['missing_reason'] === null))) {
            throw ValidationException::withMessages(['value' => __('observations.value')]);
        }
        $values = array_intersect_key($v, array_flip(Input::REVISION_FIELDS));
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = trim($value);
            }
        }
        if ($values['value'] !== null) {
            $values['value'] = str_contains($values['value'], '.') ? rtrim(rtrim($values['value'], '0'), '.') : $values['value'];
            if ($values['value'] === '-0') {
                $values['value'] = '0';
            }
        }

        return $values;
    }

    /** @param array<string,mixed> $v
     * @return array<string,mixed>
     */
    private function prepare(TenantContext $context, array $v): array
    {
        $metric = $this->metrics->find(strtolower($v['metric_id']));
        $revision = $metric->current;
        if ($revision === null || $revision->id !== strtolower($v['metric_version']) || $revision->status !== 'active' || $revision->availability === 'unavailable') {
            $this->conflict('metric');
        }
        $this->games->game($context, $metric->game_id);
        $references = [];
        foreach (['mode_id', 'role_id', 'release_id'] as $field) {
            $references[$field] = $v[$field] === null ? null : strtolower($v[$field]);
            if ($revision->getAttribute($field) !== null && $revision->getAttribute($field) !== $references[$field]) {
                throw ValidationException::withMessages([$field => __('observations.reference')]);
            }
        }
        $gameContext = $this->catalogue->resolve($metric->game_id, $references['mode_id'], $references['role_id'], $references['release_id'], true);

        $person = strtolower($v['person_id']);
        $this->people->assertAssignable($context, $person);
        $match = $v['match_id'] === null ? null : strtolower($v['match_id']);
        $part = $v['match_game_id'] === null ? null : strtolower($v['match_game_id']);
        if ($match !== null) {
            $linked = $this->matches->assertReference($context, $match, $metric->game_id, $part);
            if ($v['source_kind'] !== 'assessment' && $linked['kind'] !== $v['source_kind']) {
                throw ValidationException::withMessages(['source_kind' => __('observations.reference')]);
            }
        }
        $level = $revision->scope['aggregation_level'] ?? 'game';
        if (($part !== null && $match === null) || (in_array($level, ['game', 'round'], true) && $part === null) || ($level === 'match' && $match === null) || ($level === 'round' && $v['round_number'] === null) || ($level !== 'round' && $v['round_number'] !== null) || ($level === 'session' && $v['session_label'] === null) || ($revision->measurement_kind === 'subjective' && $v['source_kind'] !== 'assessment') || ($revision->measurement_kind !== 'subjective' && $v['source_kind'] === 'assessment') || $v['status'] !== 'active') {
            throw ValidationException::withMessages(['record' => __('observations.reference')]);
        }
        if ($v['observed_on'] > CarbonImmutable::now($context->timezone)->format('Y-m-d')) {
            throw ValidationException::withMessages(['observed_on' => __('observations.period')]);
        }
        $fields = ['source_label', 'period_start', 'period_end', 'observed_on', 'sample_size', 'source_kind'];
        $attributes = array_intersect_key($v, array_flip($fields));
        $attributes['source_label'] = trim($attributes['source_label']);
        $scope = [];
        foreach (['region', 'season', 'map', 'opponent', 'round_number', 'session_label'] as $f) {
            $scope[$f] = is_string($v[$f]) ? trim($v[$f]) : $v[$f];
        }

        return ['id' => (string) Str::uuid(), 'organization_id' => $context->organizationId, 'metric_id' => $metric->id, 'metric_revision_id' => $revision->id, 'game_id' => $metric->game_id, 'person_id' => $person, 'match_id' => $match, 'match_game_id' => $part, 'acquired_via' => 'import', 'origin_reference' => null, ...$attributes, 'context' => [...$scope, 'metric' => $this->dictionary->project($metric), 'game_context' => $gameContext], 'created_by' => $context->actorUserId, 'request_id' => $context->requestId];
    }

    private function conflict(string $key): never
    {
        throw new DomainConflictException(__('observations.'.$key), 'OBSERVATION_'.strtoupper($key));
    }
}
