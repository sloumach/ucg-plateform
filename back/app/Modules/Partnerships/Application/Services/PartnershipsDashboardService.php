<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Modules\Partnerships\Application\Contracts\PartnershipsDashboard;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipCampaignRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;
use InvalidArgumentException;

final readonly class PartnershipsDashboardService implements PartnershipsDashboard
{
    public function __construct(private SponsorshipDeliverableService $deliverables,
        private SponsorshipCampaignRepository $records,
        private TenantAuthorization $authorization) {}

    /** @return array<string,int> */
    public function summarize(TenantContext $context, string $card, DashboardWindow $window): array
    {
        $this->authorization->authorize($context, 'partnerships.read');
        if ($card !== 'overdue_deliverables') {
            throw new InvalidArgumentException('Unknown Partnerships indicator.');
        }

        return ['promised' => $this->records->dashboardCount($context->organizationId, now()->utc()->toDateTimeString())];
    }

    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed
    {
        if ($card !== 'overdue_deliverables') {
            throw new InvalidArgumentException('Unknown Partnerships dashboard card.');
        }
        $result = $this->deliverables->dashboard($context, ['page' => $window->page, 'per_page' => 5]);

        return DashboardFeed::fromPage($result['page'],
            fn (array $row): array => ['id' => $row['id'], 'label' => $row['title'], 'state' => $row['status'], 'at' => $row['due_at'], 'value' => null]);
    }
}
