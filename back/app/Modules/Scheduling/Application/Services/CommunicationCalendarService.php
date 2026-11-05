<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Application\Contracts\CommunicationCalendarDirectory;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class CommunicationCalendarService implements CommunicationCalendarDirectory
{
    public function __construct(private CalendarRepository $records, private CalendarService $calendar,
        private CalendarPeopleDirectory $people, private CalendarTeamsDirectory $teams, private TenantAuthorization $authorization) {}

    public function visibleIds(TenantContext $context): ?Builder
    {
        return $this->authorization->allows($context, 'calendar.read') ? $this->records->communicationIds($context->organizationId, $this->audience($context)) : null;
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'calendar.read');
        $rows = $this->records->communicationChoices($context->organizationId, $this->audience($context), max(1, min(100000, $page)), max(1, min(100, $perPage)));

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (CalendarActivity $row): array => ['id' => $row->id, 'label' => $row->title], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }

    public function resolve(TenantContext $context, string $id): array
    {
        $row = $this->calendar->find($context, $id, []);
        if ($row->cancelled_at !== null || $row->archived_at !== null) {
            throw new ModelNotFoundException;
        }

        return ['id' => $row->id, 'label' => $row->title, 'version' => $row->version];
    }

    private function audience(TenantContext $context): CalendarAudience
    {
        return new CalendarAudience($context->actorUserId, $this->people->viewer($context)['person_id'], $this->teams->currentIds($context));
    }
}
