<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\PeopleDashboard;
use App\Modules\People\Application\Data\PersonListQuery;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;
use InvalidArgumentException;

final readonly class PeopleDashboardService implements PeopleDashboard
{
    public function __construct(private PersonService $people, private PersonImportService $imports) {}

    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed
    {
        if ($card === 'people_imports') {
            return DashboardFeed::fromPage($this->imports->list($context, $window->page),
                fn (array $row): array => ['id' => $row['id'], 'label' => __('dashboards.import').' · '.substr($row['id'], -8), 'state' => $row['status'], 'at' => null, 'value' => null]);
        }
        if (! in_array($card, ['active_people', 'unlinked_people'], true)) {
            throw new InvalidArgumentException('Unknown People dashboard card.');
        }
        $filters = $card === 'active_people' ? ['view' => 'sports', 'status' => 'active'] : ['view' => 'administrative', 'account_linked' => 'unlinked'];
        $page = $this->people->paginate($context, new PersonListQuery([...$filters, 'page' => $window->page, 'per_page' => 5]));

        return DashboardFeed::fromPage($page, fn (array $row): array => ['id' => $row['id'],
            'label' => $card === 'active_people' ? $row['pseudonym'] : __('dashboards.unlinked').' · '.substr($row['id'], -8),
            'state' => $card === 'active_people' ? 'active' : 'unlinked', 'at' => null, 'value' => null]);
    }
}
