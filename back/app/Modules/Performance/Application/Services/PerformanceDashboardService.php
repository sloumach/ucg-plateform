<?php

namespace App\Modules\Performance\Application\Services;

use App\Modules\Performance\Application\Contracts\PerformanceDashboard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;
use InvalidArgumentException;

final readonly class PerformanceDashboardService implements PerformanceDashboard
{
    public function __construct(private StatisticalCalculationService $calculations) {}

    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed
    {
        if ($card !== 'calculations') {
            throw new InvalidArgumentException('Unknown Performance dashboard card.');
        }

        return DashboardFeed::fromPage($this->calculations->index($context, $window->page),
            fn (array $row): array => ['id' => $row['id'], 'label' => $row['metric']['code'].' · '.$row['cohort']['source_label'],
                'state' => $row['status'], 'at' => null, 'value' => $row['result']['value'] ?? null]);
    }
}
