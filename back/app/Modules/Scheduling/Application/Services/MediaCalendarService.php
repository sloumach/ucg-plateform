<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Application\Contracts\CommunicationCalendarDirectory;
use App\Modules\Scheduling\Application\Contracts\MediaCalendarDirectory;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class MediaCalendarService implements MediaCalendarDirectory
{
    public function __construct(private CommunicationCalendarDirectory $source, private CalendarRepository $records) {}

    public function visibleIds(TenantContext $context): ?Builder
    {
        return $this->source->visibleIds($context);
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->source->choices($context, $page, $perPage);
    }

    public function resolve(TenantContext $context, string $id): array
    {
        return $this->source->resolve($context, $id);
    }

    public function constraints(TenantContext $context): array
    {
        $visible = $this->visibleIds($context);

        return ['visible' => $visible,
            'shared' => $visible === null ? null : (clone $visible)->where('calendar_activities.visibility', 'organization'),
            'scrim' => $visible === null ? null : (clone $visible)->where('calendar_activities.kind', 'scrim')];
    }

    public function metadata(TenantContext $context, array $ids): array
    {
        if (count($ids) > 100) {
            throw new InvalidArgumentException('Media source metadata requires a bounded page.');
        }
        $visible = $this->visibleIds($context);

        return $visible === null || $ids === [] ? [] : $this->records->mediaMetadata($context->organizationId, $ids, $visible);
    }
}
