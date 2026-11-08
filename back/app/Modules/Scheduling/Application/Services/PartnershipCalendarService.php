<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Application\Contracts\MediaCalendarDirectory;
use App\Modules\Scheduling\Application\Contracts\PartnershipCalendarDirectory;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class PartnershipCalendarService implements PartnershipCalendarDirectory
{
    public function __construct(private MediaCalendarDirectory $source, private CalendarRepository $records, private TenantAuthorization $authorization) {}

    public function visibleIds(TenantContext $context): ?Builder
    {
        return $this->authorization->allows($context, 'partnerships.read') ? $this->source->constraints($context)['shared'] : null;
    }

    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->authorization->authorize($context, 'calendar.read');
        $visible = $this->visibleIds($context) ?? throw new ModelNotFoundException;
        $rows = $this->records->partnershipChoices($context->organizationId, $visible, $page, $perPage);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn ($row): array => ['id' => $row->id, 'label' => $row->title, 'version' => $row->version], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }

    public function metadata(TenantContext $context, array $ids): array
    {
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Exposure references require a bounded page.');
        }
        $visible = $this->visibleIds($context);
        $rows = $visible === null || $ids === [] ? [] : $this->records->mediaMetadata($context->organizationId, $ids, $visible);

        return array_map(fn (array $row): array => array_intersect_key($row, array_flip(['id', 'label', 'version'])), $rows);
    }
}
