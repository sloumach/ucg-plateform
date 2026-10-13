<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\People\Application\Contracts\PersonDepartureParticipant;
use App\Modules\Teams\Domain\Contracts\TeamMembershipRepository;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;

final readonly class TeamDepartureParticipant implements PersonDepartureParticipant
{
    public function __construct(private TeamMembershipRepository $records, private TeamMembershipService $memberships) {}

    public function closeForDeparture(TenantContext $context, string $personId, CarbonImmutable $at): void
    {
        foreach ($this->records->openForPerson($context->organizationId, $personId, $at) as $membership) {
            $this->memberships->finish($context, $membership, $at, 'person_departure', 'person_departure');
        }
    }
}
