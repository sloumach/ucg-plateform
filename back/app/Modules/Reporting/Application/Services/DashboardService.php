<?php

namespace App\Modules\Reporting\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\CompetitionDashboard;
use App\Modules\Contracts\Application\Contracts\ContractsDashboard;
use App\Modules\Partnerships\Application\Contracts\PartnershipsDashboard;
use App\Modules\People\Application\Contracts\PeopleDashboard;
use App\Modules\Performance\Application\Contracts\PerformanceDashboard;
use App\Modules\Reporting\Application\Validation\DashboardInput;
use App\Modules\Reporting\Domain\DashboardCatalogue;
use App\Modules\Scheduling\Application\Contracts\SchedulingDashboard;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardWindow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\QueryException;
use LogicException;
use Psr\Log\LoggerInterface;

final readonly class DashboardService
{
    public function __construct(private DashboardCatalogue $catalogue, private TenantAuthorization $authorization, private Factory $validator,
        private PeopleDashboard $people, private SchedulingDashboard $scheduling, private CompetitionDashboard $competition,
        private ContractsDashboard $contracts, private PartnershipsDashboard $partnerships, private PerformanceDashboard $performance,
        private LoggerInterface $logger) {}

    public function canRead(TenantContext $context): bool
    {
        foreach (DashboardCatalogue::VIEWS as $view) {
            if ($this->authorization->allows($context, 'reporting.'.$view)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function index(TenantContext $context, array $input): array
    {
        if (! $this->canRead($context)) {
            throw new AuthorizationException;
        }
        DashboardInput::validate($this->validator, $input, 'index');
        $views = [];
        foreach (DashboardCatalogue::VIEWS as $view) {
            if (! $this->authorization->allows($context, 'reporting.'.$view)) {
                continue;
            }
            $cards = [];
            foreach ($this->catalogue->cards() as $id => $card) {
                if (in_array($view, $card['views'], true) && $this->permitted($context, $card['permissions'])) {
                    $cards[] = $this->describe($id, $card);
                }
            }
            $views[] = ['id' => $view, 'label' => __('dashboards.views.'.$view), 'cards' => $cards];
        }

        return ['organization_id' => $context->organizationId, 'timezone' => $context->timezone, 'max_days' => DashboardWindow::MAX_DAYS, 'views' => $views];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function show(TenantContext $context, string $id, array $input): array
    {
        $values = DashboardInput::validate($this->validator, $input, 'show');
        $this->authorization->authorize($context, 'reporting.'.$values['view']);
        $card = $this->catalogue->find($id);
        if (! in_array($values['view'], $card['views'], true) || ! $this->permitted($context, $card['permissions'])) {
            throw new AuthorizationException;
        }
        $window = new DashboardWindow($values['from'], $values['to'], $context->timezone, (int) ($values['page'] ?? 1));
        $base = [...$this->describe($id, $card), 'organization_id' => $context->organizationId, 'view' => $values['view'],
            'period' => ['kind' => $card['period'], 'from' => $card['period'] === 'window' ? $window->from : null,
                'to' => $card['period'] === 'window' ? $window->to : null, 'timezone' => $context->timezone]];
        if ($card['source'] === 'none') {
            return [...$base, 'captured_at' => CarbonImmutable::now()->toISOString(), 'total' => null, 'items' => [], 'meta' => null];
        }
        try {
            $source = match ($card['source']) {
                'people' => $this->people, 'scheduling' => $this->scheduling, 'competition' => $this->competition,
                'contracts' => $this->contracts, 'partnerships' => $this->partnerships, 'performance' => $this->performance,
                default => throw new LogicException('Unknown reporting source.'),
            };
            $feed = $source->read($context, $id, $window);
        } catch (QueryException|DecryptException $exception) {
            $this->logger->warning('reporting.source_unavailable', ['tenant_id' => $context->organizationId, 'card' => $id,
                'request_id' => $context->requestId, 'exception_type' => $exception::class]);
            throw new DomainConflictException(__('dashboards.source_unavailable'), 'REPORTING_SOURCE_UNAVAILABLE');
        }

        return [...$base, 'captured_at' => CarbonImmutable::now()->toISOString(), 'total' => $feed->meta['total'], 'items' => $feed->items, 'meta' => $feed->meta];
    }

    /** @return array<string,mixed> */
    public function authorizeCard(TenantContext $context, string $id, string $view): array
    {
        $this->authorization->authorize($context, 'reporting.'.$view);
        $card = $this->catalogue->find($id);
        if (! in_array($view, $card['views'], true) || ! $this->permitted($context, $card['permissions'])) {
            throw new AuthorizationException;
        }

        return $card;
    }

    /** @return array<string,int> */
    public function summarize(TenantContext $context, string $id, string $view, DashboardWindow $window): array
    {
        $card = $this->authorizeCard($context, $id, $view);

        return match ($card['source']) {
            'people' => $this->people->summarize($context, $id, $window), 'contracts' => $this->contracts->summarize($context, $id, $window),
            'partnerships' => $this->partnerships->summarize($context, $id, $window), 'competition' => $this->competition->summarize($context, $id, $window),
            default => throw new LogicException('Unsupported indicator source.'),
        };
    }

    /** @param list<string> $permissions */
    private function permitted(TenantContext $context, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->authorization->allows($context, $permission)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $card
     * @return array<string,mixed> */
    private function describe(string $id, array $card): array
    {
        return ['id' => $id, 'label' => __('dashboards.cards.'.$id.'.label'), 'definition' => __('dashboards.cards.'.$id.'.definition'),
            'period_kind' => $card['period'], 'scope' => $card['scope'], 'status' => $card['reason'] === null ? 'available' : 'unavailable',
            'indicator_supported' => in_array($id, DashboardIndicatorService::CARDS, true), 'reason' => $card['reason'] === null ? null : __('dashboards.'.$card['reason'])];
    }
}
