<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Contracts\PartnershipContractDirectory;
use App\Modules\Media\Application\Contracts\PartnershipMediaDirectory;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use App\Modules\People\Application\Contracts\PartnershipPeopleDirectory;
use App\Modules\Scheduling\Application\Contracts\PartnershipCalendarDirectory;
use App\Modules\Teams\Application\Contracts\PartnershipTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\PartnershipMemberDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Support\Partnerships\ExposureSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final readonly class SponsorshipReferenceService
{
    public function __construct(private PartnershipPeopleDirectory $people, private PartnershipTeamsDirectory $teams,
        public PartnershipContractDirectory $contracts, private PartnershipMemberDirectory $members,
        private TenantNotificationRecipientDirectory $recipients, private PartnershipCalendarDirectory $calendar, private PartnershipMediaDirectory $media) {}

    public function source(string $type): ExposureSource
    {
        return match ($type) {
            'event' => $this->calendar, 'content' => $this->media,
            default => throw new \InvalidArgumentException('Unsupported exposure source.'),
        };
    }

    /** @return array<string, Builder|null> */
    public function constraints(TenantContext $context): array
    {
        return ['activity_id' => $this->calendar->visibleIds($context), 'media_id' => $this->media->visibleIds($context)];
    }

    /** @return array{id: string, label: string, version: string} */
    public function resolve(TenantContext $context, string $type, string $id, ?string $version): array
    {
        $source = $this->source($type)->metadata($context, [strtolower($id)])[strtolower($id)] ?? throw new ModelNotFoundException;
        if ($version === null) {
            throw ValidationException::withMessages(['target_version' => __('sponsorships.target_version_required')]);
        }
        if ($source['version'] !== strtolower($version)) {
            throw new DomainConflictException(__('sponsorships.target_stale'), 'SPONSORSHIP_TARGET_VERSION_STALE');
        }

        return $source;
    }

    /** @param list<SponsorshipExposure> $exposures
     * @return array<string, array{id: string, label: string, version: string}> */
    public function contextualMetadata(TenantContext $context, array $exposures): array
    {
        $details = [];
        foreach (['event', 'content'] as $type) {
            $ids = [];
            foreach ($exposures as $exposure) {
                if ($exposure->context?->type() === $type) {
                    $ids[] = $exposure->context->sourceId();
                }
            }
            if ($ids !== []) {
                $details[$type] = $this->source($type)->metadata($context, array_values(array_unique($ids)));
            }
        }
        $result = [];
        foreach ($exposures as $exposure) {
            if ($exposure->context !== null && isset($details[$exposure->context->type()][$exposure->context->sourceId()])) {
                $result[$exposure->id] = $details[$exposure->context->type()][$exposure->context->sourceId()];
            }
        }

        return $result;
    }

    /** Recheck after selection so a source restriction cannot leak a stored label or proof.
     * @param list<SponsorshipExposure> $exposures */
    public function assertVisible(TenantContext $context, array $exposures): void
    {
        $metadata = $this->contextualMetadata($context, $exposures);
        foreach ($exposures as $exposure) {
            if ($exposure->context !== null && ! isset($metadata[$exposure->id])) {
                throw new ModelNotFoundException;
            }
        }
    }

    /** @return LengthAwarePaginator<int, array{id: string, label: string}>|LengthAwarePaginator<int, array{id: string, label: string, version: string}> */
    public function paginate(TenantContext $context, string $kind, int $page, int $perPage): LengthAwarePaginator
    {
        return match ($kind) {
            'events' => $this->calendar->paginate($context, $page, $perPage),
            'contents' => $this->media->paginate($context, $page, $perPage),
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
        $metadata = $this->contextualMetadata($context, $exposures);
        $result = [];
        foreach ($exposures as $exposure) {
            $result[$exposure->id] = $exposure->status === 'active' && ($exposure->context !== null
                ? isset($metadata[$exposure->id]) && $metadata[$exposure->id]['version'] === $exposure->context->source_version
                : match ($exposure->target_type) {
                    'organization' => true, 'team' => isset($teamLabels[$exposure->team_id ?? '']),
                    'player' => isset($peopleLabels[$exposure->person_id ?? '']), default => false,
                });
        }

        return $result;
    }
}
