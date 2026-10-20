<?php

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class DashboardIndicatorProcessor
{
    public function __construct(private DashboardIndicatorService $service, private DashboardService $dashboards,
        private DashboardIndicatorRepository $records, private TenantBusinessGuard $guard, private ConnectionInterface $database) {}

    public function process(TenantContext $context, string $id, string $version): void
    {
        if (! $this->service->enabled()) {
            $this->service->conflict('disabled');
        }
        $row = $this->records->find($context->organizationId, $context->actorUserId, $id);
        $this->dashboards->authorizeCard($context, $row->card, $row->view);
        if ($row->version !== $version || $row->status !== 'queued') {
            return;
        }
        if ($context->timezone !== $row->timezone) {
            $this->service->conflict('stale');
        }
        $source = $this->records->generation($context->organizationId, $row->card);
        $evaluated = CarbonImmutable::now()->utc();
        $counts = $this->dashboards->summarize($context, $row->card, $row->view, new DashboardWindow($row->from_date, $row->to_date, $row->timezone));
        ksort($counts);
        $result = ['total' => array_sum($counts), 'groups' => array_map(fn ($state, $count) => ['state' => $state, 'count' => $count], array_keys($counts), array_values($counts))];
        $this->database->transaction(function () use ($context, $id, $version, $source, $evaluated, $result): void {
            $this->guard->lock($context);
            $current = $this->records->find($context->organizationId, $context->actorUserId, $id, true);
            $this->dashboards->authorizeCard($context, $current->card, $current->view);
            if (! $this->service->enabled()) {
                $this->service->conflict('disabled');
            }
            if ($current->version !== $version || $current->status !== 'queued') {
                return;
            }
            if ($source !== $this->records->generation($context->organizationId, $current->card, true) || $evaluated->addSeconds(DashboardIndicatorService::LIFETIME_SECONDS)->isPast()) {
                $this->records->save($current, ['status' => 'failed', 'failure_code' => 'SOURCE_CHANGED']);

                return;
            }
            $number = ($current->current->number ?? 0) + 1;
            if ($number > 1000) {
                $this->service->conflict('limit');
            }
            $this->records->append(['organization_id' => $context->organizationId, 'indicator_id' => $id, 'number' => $number, 'generation' => $version,
                'source_version' => $source, 'engine_version' => DashboardIndicatorService::ENGINE, 'result' => $result, 'evaluated_at' => $evaluated,
                'expires_at' => $evaluated->addSeconds(DashboardIndicatorService::LIFETIME_SECONDS), 'request_id' => $context->requestId, 'created_at' => now()]);
            $this->records->save($current, ['status' => 'completed', 'failure_code' => null]);
            $this->service->record($context, $current, 'calculated');
        }, 3);
    }
}
