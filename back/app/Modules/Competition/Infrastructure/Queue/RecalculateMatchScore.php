<?php

namespace App\Modules\Competition\Infrastructure\Queue;

use App\Modules\Competition\Application\Services\MatchCorrectionService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Support\Queue\JobContext;

final class RecalculateMatchScore extends TenantJob
{
    public function __construct(JobContext $context, public readonly string $matchId, public readonly string $correctionId)
    {
        parent::__construct($context);
    }

    public function handle(MatchCorrectionService $service, TenantContext $context): void
    {
        $service->process($context, $this->matchId, $this->correctionId);
    }
}
