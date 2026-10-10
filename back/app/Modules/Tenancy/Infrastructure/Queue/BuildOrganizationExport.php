<?php

namespace App\Modules\Tenancy\Infrastructure\Queue;

use App\Exceptions\DomainException;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Trusted operator work, not an ordinary active-tenant business job or an HTTP entry point. */
final class BuildOrganizationExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $organizationId, public readonly string $operationId)
    {
        $this->onQueue('reports');
        $this->afterCommit();
    }

    public function handle(OrganizationPortabilityService $exports): void
    {
        try {
            $exports->generate($this->organizationId, $this->operationId);
        } catch (DomainException $exception) {
            if ($exception->errorCode() === 'PORTABILITY_BUSY') {
                $this->release(5);

                return;
            }
            $this->fail($exception);
        } catch (ModelNotFoundException $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(OrganizationPortabilityService::class)->failed($this->organizationId, $this->operationId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['tenant:'.$this->organizationId, 'export:'.$this->operationId];
    }
}
