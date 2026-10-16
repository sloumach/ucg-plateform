<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Contracts\PartnershipContractDirectory;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use App\Modules\People\Application\Contracts\PartnershipPeopleDirectory;
use App\Modules\Teams\Application\Contracts\PartnershipTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\PartnershipMemberDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class SponsorshipReferenceService
{
    public function __construct(private PartnershipPeopleDirectory $people, private PartnershipTeamsDirectory $teams,
        public PartnershipContractDirectory $contracts, private PartnershipMemberDirectory $members,
        private TenantNotificationRecipientDirectory $recipients) {}

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function paginate(TenantContext $context, string $kind, int $page, int $perPage): LengthAwarePaginator
    {
        return match ($kind) {
            'players' => $this->people->paginate($context, $page, $perPage),
            'teams' => $this->teams->paginate($context, $page, $perPage),
            'contracts' => $this->contracts->paginate($context, $page, $perPage),
            'members' => $this->members->paginate($context, $page, $perPage),
            default => throw new \InvalidArgumentException('Unsupported agreement reference.'),
        };
    }

    public function responsibleEligible(TenantContext $context, int $id): bool
    {
        return $this->members->isEligible($context, $id);
    }

    public function approverEligible(TenantContext $context, int $id): bool
    {
        return $this->recipients->email($context->organizationId, $id, $context->requestId,
            ['partnerships.read', 'partnerships.write', 'partnerships.validate', 'financial.read', 'financial.validate']) !== null;
    }

    public function label(TenantContext $context, string $type, string $id): string
    {
        $id = strtolower($id);
        if ($type === 'organization') {
            if ($id !== $context->organizationId) {
                throw new ModelNotFoundException;
            }

            return $context->organizationName !== '' ? $context->organizationName : $context->slug;
        }
        if (! in_array($type, ['team', 'player'], true)) {
            throw new DomainConflictException(__('sponsorships.unsupported'), 'SPONSORSHIP_TARGET_UNAVAILABLE');
        }
        $labels = $type === 'team' ? $this->teams->labels($context, [$id]) : $this->people->labels($context, [$id]);
        if (! isset($labels[$id])) {
            throw new ModelNotFoundException;
        }

        return $labels[$id];
    }

    /** Batch verification keeps query counts independent of the number of scopes in a bounded page.
     * @param  list<SponsorshipExposure>  $exposures
     * @return array<string, bool>
     */
    public function eligibility(TenantContext $context, array $exposures): array
    {
        $teams = array_values(array_unique(array_filter(array_map(fn (SponsorshipExposure $e): ?string => $e->team_id, $exposures))));
        $people = array_values(array_unique(array_filter(array_map(fn (SponsorshipExposure $e): ?string => $e->person_id, $exposures))));
        $teamLabels = $teams === [] ? [] : $this->teams->labels($context, $teams);
        $peopleLabels = $people === [] ? [] : $this->people->labels($context, $people);
        $result = [];
        foreach ($exposures as $exposure) {
            $result[$exposure->id] = $exposure->status === 'active' && match ($exposure->target_type) {
                'organization' => true, 'team' => isset($teamLabels[$exposure->team_id ?? '']),
                'player' => isset($peopleLabels[$exposure->person_id ?? '']), default => false,
            };
        }

        return $result;
    }
}
