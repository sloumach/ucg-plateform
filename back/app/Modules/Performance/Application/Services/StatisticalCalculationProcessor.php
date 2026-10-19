<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Performance\Domain\Aggregation\StatisticalCalculator;
use App\Modules\Performance\Domain\Contracts\StatisticalCalculationRepository;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class StatisticalCalculationProcessor
{
    public function __construct(private StatisticalCalculationRepository $records, private StatisticalCalculationService $service,
        private StatisticalCalculator $calculator, private TenantBusinessGuard $guard,
        private MatchReferenceDirectory $games, private ConnectionInterface $database) {}

    public function process(TenantContext $context, string $id, string $version): void
    {
        $this->service->authorize($context, true);
        $this->service->enabled();
        $calculation = $this->service->find($context, $id);
        if ($calculation->version !== $version || ! in_array($calculation->status, ['queued', 'stale'], true)) {
            return;
        }
        // Read and calculate outside the lock; a committed source correction changes the generation.
        $ids = $this->records->sourceIds($context->organizationId, $id);
        $rows = $this->service->inputs($context, $ids);
        $result = $this->calculator->calculate($calculation->definition['metric'], $rows);
        $sourceVersions = array_column($rows, 'version', 'id');
        $this->database->transaction(function () use ($context, $id, $version, $result, $sourceVersions): void {
            $this->guard->lock($context);
            $this->service->authorize($context, true);
            $this->service->enabled();
            $current = $this->service->find($context, $id, true);
            $this->games->game($context, $current->definition['metric']['game_id']);
            if ($current->version !== $version || ! in_array($current->status, ['queued', 'stale'], true)) {
                return;
            }
            if (($current->current->number ?? 0) >= StatisticalCalculationService::MAX_REVISIONS) {
                throw new DomainConflictException(__('statistical_calculations.limit'), 'STAT_CALCULATION_LIMIT');
            }
            $this->records->append(['organization_id' => $context->organizationId, 'calculation_id' => $current->id,
                'number' => ($current->current->number ?? 0) + 1, 'generation' => $version,
                'engine_version' => StatisticalCalculator::ENGINE_VERSION, 'result' => $result,
                'source_versions' => $sourceVersions, 'request_id' => $context->requestId]);
            $this->records->save($current, ['status' => 'completed', 'failure_code' => null]);
            $this->service->record($context, $current, 'calculated');
        });
    }

    /** Failure metadata only; a callback from an old generation must not poison a newer result. */
    public function failed(string $organization, int $actor, string $id, string $version): void
    {
        try {
            $this->database->transaction(function () use ($organization, $actor, $id, $version): void {
                $calculation = $this->records->metadata($organization, $actor, $id, true);
                if ($calculation->version === $version && in_array($calculation->status, ['queued', 'stale'], true)) {
                    $this->records->save($calculation, ['status' => 'failed', 'failure_code' => 'CALCULATION_INTERRUPTED']);
                }
            });
        } catch (ModelNotFoundException) {
            // A missing maintenance target needs no operational update.
        }
    }
}
