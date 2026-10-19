<?php

namespace App\Modules\Performance\Infrastructure\Queue;

use App\Modules\Performance\Application\Contracts\StatisticalCalculationQueue;
use App\Modules\Performance\Application\Services\ObservationService;
use App\Modules\Performance\Domain\Contracts\StatisticalCalculationRepository;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;

final class RecalculateObservationStatistics extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $observationId, public readonly ?string $after = null)
    {
        parent::__construct($context);
        $this->onQueue('reports');
    }

    public function handle(StatisticalCalculationRepository $records, StatisticalCalculationQueue $queue,
        ObservationService $observations, TenantContext $context): void
    {
        $observations->authorize($context);
        if (! config('statistical_calculations.processing_enabled', false)) {
            return;
        }
        $rows = $records->affected($context->organizationId, $this->observationId, $this->after);
        foreach ($rows as $row) {
            // Results are recalculated under the original reader's current authority, never the correcting actor's scope.
            $queue->dispatch(new JobContext($context->requestId, $context->organizationId, $row->actor_user_id), $row->id, $row->version);
        }
        if (count($rows) === 25) {
            $queue->affected($this->context, $this->observationId, $rows[array_key_last($rows)]->id);
        }
    }
}
