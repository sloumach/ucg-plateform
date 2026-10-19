<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Application\Contracts\SchedulingDashboard;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;
use InvalidArgumentException;

final readonly class SchedulingDashboardService implements SchedulingDashboard
{
    public function __construct(private CalendarService $calendar, private CalendarInvitationService $invitations,
        private AvailabilityService $availability) {}

    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed
    {
        $filters = ['from_date' => $window->from, 'to_date' => $window->exclusiveTo(), 'timezone' => $window->timezone, 'page' => $window->page, 'per_page' => 5];

        return match ($card) {
            'activities' => DashboardFeed::fromPage($this->calendar->paginate($context, $filters)->activities,
                fn (CalendarActivity $row): array => ['id' => $row->id, 'label' => $row->title, 'state' => $row->cancelled_at === null ? 'scheduled' : 'cancelled',
                    'at' => $row->starts_at->toISOString(), 'value' => null]),
            'invitations' => DashboardFeed::fromPage($this->invitations->paginate($context, ['page' => $window->page, 'per_page' => 5]),
                fn (CalendarInvitation $row): array => ['id' => $row->id, 'label' => $row->activity->title, 'state' => $row->response,
                    'at' => $row->activity->starts_at->toISOString(), 'value' => null]),
            'availability' => DashboardFeed::fromPage($this->availability->paginate($context, $filters)->records,
                fn (Availability $row): array => ['id' => $row->id, 'label' => __('dashboards.availability'), 'state' => $row->status,
                    'at' => $row->starts_at->toISOString(), 'value' => null]),
            default => throw new InvalidArgumentException('Unknown Scheduling dashboard card.'),
        };
    }
}
