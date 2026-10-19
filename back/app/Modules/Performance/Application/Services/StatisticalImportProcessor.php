<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Performance\Domain\Contracts\StatisticalImportRepository;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class StatisticalImportProcessor
{
    public const CHUNK_SIZE = 10;

    public function __construct(private StatisticalImportRepository $imports, private StatisticalImportService $service,
        private ObservationService $observations, private TenantBusinessGuard $guard, private ConnectionInterface $database) {}

    /** Each line commits independently; a retry starts after the last committed outcome. */
    public function process(TenantContext $context, string $id, string $version): bool
    {
        for ($offset = 0; $offset < self::CHUNK_SIZE; $offset++) {
            $more = $this->database->transaction(function () use ($context, $id, $version): bool {
                $this->guard->lock($context);
                $this->service->authorize($context);
                $run = $this->imports->find($context->organizationId, $context->actorUserId, $id, true);
                $execution = $run->execution;
                if ($execution === null || $execution->version !== $version || $execution->status !== 'queued') {
                    return false;
                }
                if (! config('statistical_imports.processing_enabled', false)) {
                    throw new DomainConflictException(__('statistical_imports.disabled'), 'STAT_IMPORT_DISABLED');
                }
                $row = $run->preview[$execution->processed_count];
                $outcome = ['status' => 'rejected', 'code' => 'PREVIEW_INVALID'];
                if ($row['status'] === 'valid') {
                    // Only draft errors are terminal row refusals; persistence/audit failures must retry.
                    $outcome = $this->observations->import($context, $row['values'], $run->id);
                }
                $results = $execution->results;
                $results[$row['line']] = $outcome;
                $processed = $execution->processed_count + 1;
                $more = $processed < $run->row_count;
                $this->imports->saveExecution($execution, ['results' => $results, 'processed_count' => $processed,
                    'status' => $more ? 'queued' : 'completed', 'failure_code' => null]);

                return $more;
            }, 3);
            if (! $more) {
                return false;
            }
        }

        return true;
    }

    /** Updates operational metadata only, including when tenant middleware rejects the actor. */
    public function failed(string $organization, int $actor, string $id, string $version): void
    {
        try {
            $this->database->transaction(function () use ($organization, $actor, $id, $version): void {
                $execution = $this->imports->find($organization, $actor, $id, true)->execution;
                if ($execution !== null && $execution->version === $version && $execution->status === 'queued') {
                    $this->imports->saveExecution($execution, ['status' => 'failed', 'failure_code' => 'PROCESSING_INTERRUPTED']);
                }
            });
        } catch (ModelNotFoundException) {
            // A missing target has no operational metadata to update.
        }
    }
}
