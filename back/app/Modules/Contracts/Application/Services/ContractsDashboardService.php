<?php

namespace App\Modules\Contracts\Application\Services;

use App\Modules\Contracts\Application\Contracts\ContractsDashboard;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;
use InvalidArgumentException;

final readonly class ContractsDashboardService implements ContractsDashboard
{
    public function __construct(private ContractRepository $records, private TenantAuthorization $authorization) {}

    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed
    {
        $this->authorization->authorize($context, 'administrative.read');
        if ($card !== 'expiring_contracts') {
            throw new InvalidArgumentException('Unknown Contracts dashboard card.');
        }

        return DashboardFeed::fromPage($this->records->expiring($context->organizationId, $window->from, $window->exclusiveTo(), $window->page),
            fn (ContractRecord $row): array => ['id' => $row->id, 'label' => $row->reference, 'state' => $row->status, 'at' => $row->ends_on?->format('Y-m-d'), 'value' => null]);
    }
}
