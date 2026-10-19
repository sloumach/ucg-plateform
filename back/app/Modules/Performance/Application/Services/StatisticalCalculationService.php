<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\ObservationMatchDirectory;
use App\Modules\Performance\Application\Contracts\StatisticalCalculationQueue;
use App\Modules\Performance\Application\Validation\StatisticalCalculationInput as Input;
use App\Modules\Performance\Domain\Aggregation\StatisticalCalculator;
use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalCalculationRepository;
use App\Modules\Performance\Domain\Models\StatisticalCalculation;
use App\Modules\Performance\Domain\Models\StatisticalCalculationRevision;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class StatisticalCalculationService
{
    public const MAX_CALCULATIONS = 100;

    public const MAX_REVISIONS = 1000;

    public function __construct(private StatisticalCalculationRepository $records, private MetricCatalogueRepository $metrics,
        private MetricCatalogueService $dictionary, private ObservationService $observations,
        private ObservationMatchDirectory $matches, private MatchReferenceDirectory $games, private StatisticalCalculator $calculator,
        private TenantBusinessGuard $guard, private ConnectionInterface $database, private Factory $validator,
        private Encrypter $crypt, private StatisticalCalculationQueue $queue, private TenantAuditJournal $audit, private LoggerInterface $logger) {}

    public function authorize(TenantContext $context, bool $write = false): void
    {
        $this->observations->authorize($context, $write ? 'create' : 'read');
    }

    public function find(TenantContext $context, string $id, bool $lock = false): StatisticalCalculation
    {
        $this->authorize($context);

        return $this->records->find($context->organizationId, $context->actorUserId, $id, $this->matches->visibleIds($context), $lock);
    }

    /** @param list<string> $ids
     * @return list<array<string,mixed>> */
    public function inputs(TenantContext $context, array $ids): array
    {
        $this->authorize($context);
        $records = $this->records->observations($context->organizationId, $ids, $this->matches->visibleIds($context));
        if (count($records) !== count($ids) || count(array_filter($records, fn ($row): bool => $row->current !== null)) !== count($ids)) {
            throw new ModelNotFoundException;
        }

        return array_map(fn ($row) => [...$this->observations->project($row, []), 'created_at' => $row->created_at->toISOString()], $records);
    }

    /** @param array<string,mixed> $input */
    public function create(TenantContext $context, array $input): StatisticalCalculation
    {
        $this->authorize($context, true);
        $v = Input::validate($this->validator, $input, 'store');
        $ids = array_map('strtolower', $v['observations']);
        sort($ids);
        $fingerprint = hash_hmac('sha256', json_encode([$context->organizationId, $context->actorUserId, strtolower($v['metric_id']), strtolower($v['metric_version']), $ids], JSON_THROW_ON_ERROR), $this->crypt->getKey());
        $created = false;
        $calculation = $this->database->transaction(function () use ($context, $v, $ids, $fingerprint, &$created): StatisticalCalculation {
            $this->guard->lock($context);
            $this->authorize($context, true);
            $existing = $this->records->fingerprint($context->organizationId, $context->actorUserId, $fingerprint);
            if ($existing !== null) {
                return $this->find($context, $existing->id);
            }
            $this->enabled();
            if ($this->records->count($context->organizationId, $context->actorUserId) >= self::MAX_CALCULATIONS) {
                $this->conflict('limit');
            }
            $metric = $this->metrics->find(strtolower($v['metric_id']));
            $revision = $this->metrics->revision($metric->id, strtolower($v['metric_version']));
            $this->games->game($context, $metric->game_id);
            if ($revision->getAttribute('status') !== 'active' || $revision->getAttribute('availability') === 'unavailable') {
                $this->conflict('metric');
            }
            $definition = $this->dictionary->project($metric, $revision);
            $rows = $this->inputs($context, $ids);
            try {
                $this->calculator->validate($definition, $rows);
            } catch (DomainException) {
                throw ValidationException::withMessages(['observations' => __('statistical_calculations.incompatible')]);
            }
            $cohort = array_intersect_key($rows[0], array_flip(['person_id', 'source_kind', 'source_label']));
            $calculation = $this->records->create(['organization_id' => $context->organizationId, 'actor_user_id' => $context->actorUserId,
                'metric_id' => $metric->id, 'metric_revision_id' => $revision->id, 'fingerprint' => $fingerprint,
                'version' => (string) Str::uuid(), 'status' => 'queued', 'source_count' => count($ids),
                'definition' => ['metric' => $definition, 'cohort' => $cohort], 'failure_code' => null], $ids);
            $this->record($context, $calculation, 'requested');
            $created = true;

            return $calculation;
        });
        if ($created) {
            $this->dispatch($context, $calculation);
        }

        return $this->find($context, $calculation->id);
    }

    /** @param array<string,mixed> $input */
    public function recalculate(TenantContext $context, string $id, array $input): StatisticalCalculation
    {
        $this->authorize($context, true);
        $v = Input::validate($this->validator, $input, 'recalculate');
        $calculation = $this->database->transaction(function () use ($context, $id, $v): StatisticalCalculation {
            $this->guard->lock($context);
            $this->authorize($context, true);
            $this->enabled();
            $calculation = $this->find($context, $id, true);
            if ($calculation->version !== strtolower($v['version'])) {
                $this->conflict('stale');
            }
            if (($calculation->current->number ?? 0) >= self::MAX_REVISIONS) {
                $this->conflict('limit');
            }
            $this->records->save($calculation, ['status' => 'queued', 'version' => (string) Str::uuid(), 'failure_code' => null]);
            $this->record($context, $calculation, 'recalculation_requested');

            return $calculation;
        });
        $this->dispatch($context, $calculation);

        return $this->find($context, $id);
    }

    /** @return LengthAwarePaginator<int,array<string,mixed>> */
    public function index(TenantContext $context, int $page): LengthAwarePaginator
    {
        $this->authorize($context);

        return $this->records->paginate($context->organizationId, $context->actorUserId, $this->matches->visibleIds($context), $page)
            ->through(fn ($row) => $this->project($row));
    }

    /** @return array<string,mixed> */
    public function show(TenantContext $context, string $id, int $page): array
    {
        $calculation = $this->find($context, $id);
        $ids = $this->records->sourceIds($context->organizationId, $id);

        return [...$this->project($calculation), 'sources' => ['data' => array_slice($ids, ($page - 1) * 10, 10),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($ids) / 10)), 'per_page' => 10, 'total' => count($ids)]]];
    }

    /** @return LengthAwarePaginator<int,array<string,mixed>> */
    public function history(TenantContext $context, string $id, int $page): LengthAwarePaginator
    {
        $calculation = $this->find($context, $id);

        return $this->records->history($context->organizationId, $calculation->id, $page)->through(fn ($row) => $this->revision($row));
    }

    /** @return array<string,mixed> */
    public function project(StatisticalCalculation $row): array
    {
        return ['id' => $row->id, 'status' => $row->status, 'version' => $row->version, 'source_count' => $row->source_count,
            'metric' => $row->definition['metric'], 'cohort' => $row->definition['cohort'], 'failure_code' => $row->failure_code,
            'result' => $row->status === 'completed' && $row->current?->generation === $row->version ? $row->current->result : null,
            'result_version' => $row->current?->id, 'number' => $row->current?->number,
            'can_recalculate' => (bool) config('statistical_calculations.processing_enabled', false)];
    }

    /** @return array<string,mixed> */
    private function revision(StatisticalCalculationRevision $revision): array
    {
        return ['id' => $revision->id, 'number' => $revision->number, 'engine_version' => $revision->engine_version,
            'result' => $revision->result, 'created_at' => $revision->created_at->toISOString()];
    }

    public function enabled(): void
    {
        if (! config('statistical_calculations.processing_enabled', false)) {
            $this->conflict('disabled');
        }
    }

    public function record(TenantContext $context, StatisticalCalculation $calculation, string $action): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
            AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, null, [],
            ['statistical_calculation_id' => $calculation->id, 'statistical_calculation_action' => $action]));
    }

    private function dispatch(TenantContext $context, StatisticalCalculation $calculation): void
    {
        try {
            $this->queue->dispatch($context->toJobContext(), $calculation->id, $calculation->version);
        } catch (Throwable $exception) {
            $this->logger->warning('statistics.dispatch_failed', ['tenant_id' => $context->organizationId,
                'calculation_id' => $calculation->id, 'exception_type' => $exception::class]);
            $this->conflict('dispatch_failed');
        }
    }

    private function conflict(string $reason): never
    {
        throw new DomainConflictException(__('statistical_calculations.'.$reason), 'STAT_CALCULATION_'.strtoupper($reason));
    }
}
