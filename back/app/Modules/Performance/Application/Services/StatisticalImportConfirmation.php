<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Performance\Application\Contracts\StatisticalImportQueue;
use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalImportRepository;
use App\Modules\Performance\Domain\Models\StatisticalImportExecution;
use App\Modules\Teams\Application\Contracts\MetricGameDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class StatisticalImportConfirmation
{
    public function __construct(private StatisticalImportRepository $imports, private StatisticalImportService $service,
        private ObservationService $observations, private TenantBusinessGuard $guard,
        private MetricGameDirectory $catalogue, private MetricCatalogueRepository $metrics,
        private ConnectionInterface $database, private Factory $validator,
        private StatisticalImportQueue $queue, private TenantAuditJournal $audit, private LoggerInterface $logger) {}

    /** @param array<string,mixed> $payload */
    public function confirm(TenantContext $context, string $id, array $payload, bool $resume = false): void
    {
        $this->service->authorize($context);
        $rules = self::rules($resume);
        if (array_diff(array_keys($payload), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['import' => __('observations.closed')]);
        }
        $input = $this->validator->make($payload, $rules)->validate();
        $dispatch = false;
        $execution = $this->database->transaction(function () use ($context, $id, $input, $resume, &$dispatch): StatisticalImportExecution {
            $this->guard->lock($context);
            $this->service->authorize($context);
            $run = $this->imports->find($context->organizationId, $context->actorUserId, $id, true);
            $execution = $run->execution;
            if ($resume) {
                if ($execution === null || $execution->version !== strtolower($input['version'])) {
                    $this->conflict('version');
                }
                if ($execution->status === 'completed') {
                    return $execution;
                }
            } else {
                if (strtolower($input['version']) !== $run->id) {
                    $this->conflict('version');
                }
                if ($execution !== null) {
                    return $execution;
                }
                if ($run->valid_count === 0) {
                    $this->conflict('empty');
                }
                if ($run->invalid_count > 0 && ! ($input['acknowledge_rejected'] ?? false)) {
                    $this->conflict('rejected');
                }
            }
            if (! config('statistical_imports.processing_enabled', false)) {
                $this->conflict('disabled');
            }
            $this->catalogue->lock($this->catalogue->version());
            $this->metrics->state(true);
            // Recheck all remaining valid lines before accepting the user's confirmation.
            foreach (array_slice($run->preview, $execution->processed_count ?? 0) as $row) {
                if ($row['status'] !== 'valid') {
                    continue;
                }
                try {
                    $this->observations->draft($context, $row['values']);
                } catch (ValidationException|ModelNotFoundException|DomainConflictException) {
                    $this->conflict('stale');
                }
            }
            $attributes = ['status' => 'queued', 'version' => (string) Str::uuid(), 'failure_code' => null];
            if ($execution === null) {
                $execution = $this->imports->createExecution([...$attributes, 'organization_id' => $context->organizationId,
                    'import_id' => $run->id, 'processed_count' => 0, 'results' => []]);
            } else {
                $this->imports->saveExecution($execution, $attributes);
            }
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, null, [],
                ['statistical_import_id' => $run->id, 'statistical_import_action' => $resume ? 'resumed' : 'confirmed']));
            $dispatch = true;

            return $execution;
        });
        if ($dispatch) {
            try {
                $this->queue->dispatch($context->toJobContext(), $id, $execution->version);
            } catch (Throwable $exception) {
                // No raw exception message or CSV cells are written into queue failure metadata.
                $this->logger->warning('statistical_import.dispatch_failed', ['tenant_id' => $context->organizationId,
                    'request_id' => $context->requestId, 'import_id' => $id, 'exception_type' => $exception::class]);
                throw new DomainConflictException(__('statistical_imports.dispatch_failed'), 'STAT_IMPORT_DISPATCH_FAILED');
            }
        }
    }

    /** @return array<string,list<string>> */
    public static function rules(bool $resume): array
    {
        return ['version' => ['required', 'uuid'], 'confirm' => ['required', 'accepted'],
            ...($resume ? [] : ['acknowledge_rejected' => ['sometimes', 'boolean']])];
    }

    private function conflict(string $reason): never
    {
        throw new DomainConflictException(__('statistical_imports.'.$reason), 'STAT_IMPORT_'.strtoupper($reason));
    }
}
