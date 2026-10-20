<?php

namespace App\Modules\Competition\Application\Services;

use App\Modules\Competition\Application\Contracts\CompetitionDashboard;
use App\Modules\Competition\Domain\Contracts\CompetitionRegistrationRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DashboardFeed;
use App\Support\Data\DashboardWindow;
use InvalidArgumentException;

final readonly class CompetitionDashboardService implements CompetitionDashboard
{
    public function __construct(private MatchRepository $matches, private MatchReferenceDirectory $references, private TenantAuthorization $authorization,
        private CompetitionRegistrationService $registrations, private CompetitionRegistrationRepository $registrationRecords) {}

    /** @return array<string,int> */
    public function summarize(TenantContext $context, string $card, DashboardWindow $window): array
    {
        $this->registrations->authorize($context);
        if ($card !== 'registration_due') {
            throw new InvalidArgumentException('Unknown Competition indicator.');
        }

        return ['pending' => $this->registrationRecords->dashboardCount($context->organizationId, $window->start()->toDateTimeString(), $window->end()->toDateTimeString())];
    }

    public function read(TenantContext $context, string $card, DashboardWindow $window): DashboardFeed
    {
        $this->authorization->authorize($context, 'sports.read');
        if ($card === 'registration_due') {
            $this->registrations->authorize($context);

            return DashboardFeed::fromPage($this->registrationRecords->dashboardDue($context->organizationId,
                $window->start()->toDateTimeString(), $window->end()->toDateTimeString(), $window->page),
                fn (CompetitionRegistrationRevision $row): array => ['id' => $row->competition_id, 'label' => $row->getAttribute('competition_label'),
                    'state' => 'pending', 'at' => $row->next_due_at?->toISOString(), 'value' => null]);
        }
        if ($card !== 'matches') {
            throw new InvalidArgumentException('Unknown Competition dashboard card.');
        }

        return DashboardFeed::fromPage($this->matches->dashboard($context->organizationId, $context->actorUserId,
            $this->references->viewer($context), $window->start()->toDateTimeString(), $window->end()->toDateTimeString(), $window->page),
            fn (MatchRecord $row): array => ['id' => $row->id, 'label' => $row->title, 'state' => $row->status, 'at' => $row->starts_at->toISOString(), 'value' => null]);
    }
}
