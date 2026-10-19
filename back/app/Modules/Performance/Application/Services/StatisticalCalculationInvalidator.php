<?php

namespace App\Modules\Performance\Application\Services;

use App\Modules\Performance\Application\Contracts\StatisticalCalculationQueue;
use App\Modules\Performance\Domain\Contracts\StatisticalCalculationRepository;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/** Invalidations commit with the observation; dispatch cannot make an old result fresh again. */
final readonly class StatisticalCalculationInvalidator
{
    public function __construct(private StatisticalCalculationRepository $calculations, private StatisticalCalculationQueue $queue,
        private LoggerInterface $logger, private DatabaseManager $database) {}

    public function changed(TenantContext $context, string $observation): void
    {
        if ($this->calculations->invalidate($context->organizationId, $observation, (string) Str::uuid()) > 0
            && config('statistical_calculations.processing_enabled', false)) {
            $this->database->connection()->afterCommit(function () use ($context, $observation): void {
                try {
                    $this->queue->affected($context->toJobContext(), $observation);
                } catch (Throwable $exception) {
                    $this->logger->warning('statistics.invalidation_dispatch_failed', ['tenant_id' => $context->organizationId,
                        'observation_id' => $observation, 'exception_type' => $exception::class]);
                }
            });
        }
    }
}
