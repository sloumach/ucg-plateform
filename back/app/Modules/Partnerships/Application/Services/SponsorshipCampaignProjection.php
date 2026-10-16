<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Modules\Partnerships\Domain\Contracts\SponsorshipCampaignRepository;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Tenancy\Application\Contracts\PartnershipMemberDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;

final readonly class SponsorshipCampaignProjection
{
    public function __construct(private PartnershipMemberDirectory $members, private SponsorshipCampaignRepository $records,
        private SponsorshipAgreementService $agreements, private SponsorshipReferenceService $references) {}

    /** @param list<SponsorshipCampaign> $records
     * @return list<array<string,mixed>> */
    public function campaigns(TenantContext $context, array $records): array
    {
        $labels = $this->members->labels($context, array_values(array_unique(array_map(fn (SponsorshipCampaign $r): int => $r->responsible_user_id, $records))));

        return array_map(fn (SponsorshipCampaign $r): array => ['id' => $r->id, 'agreement_id' => $r->agreement_id, 'title' => $r->title,
            'starts_on' => $r->starts_on->format('Y-m-d'), 'ends_on' => $r->ends_on->format('Y-m-d'), 'responsible_user_id' => $r->responsible_user_id,
            'responsible_label' => $labels[$r->responsible_user_id] ?? null, 'status' => $r->status, 'version' => $r->version, 'publication_status' => 'private'], $records);
    }

    /** @param list<SponsorshipDeliverable> $records
     * @return list<array<string,mixed>> */
    public function deliverables(TenantContext $context, SponsorshipAgreement $agreement, SponsorshipCampaign $campaign, array $records): array
    {
        $labels = $this->labels($context, $records);
        $scopes = $this->records->exposures($context->organizationId, $agreement->id, array_values(array_unique(array_map(fn (SponsorshipDeliverable $r): string => $r->exposure_id, $records))));
        $eligible = $this->references->eligibility($context, $scopes);
        $versions = [];
        foreach ($scopes as $scope) {
            $versions[$scope->id] = $scope->version;
        }
        $approved = $campaign->status === 'active' && $this->agreements->approvedCurrent($context, $agreement);

        return array_map(fn (SponsorshipDeliverable $r): array => [...$this->values($r, $labels),
            'proof_reference' => $r->proof_reference, 'exposure_authorized' => $approved && ($eligible[$r->exposure_id] ?? false),
            'validation_matches_agreement' => $r->status === 'validated' && $approved && ($eligible[$r->exposure_id] ?? false) && $r->validated_agreement_version === $agreement->version
                && $r->validated_exposure_version === ($versions[$r->exposure_id] ?? null),
            'validated_agreement_version' => $r->validated_agreement_version, 'validated_exposure_version' => $r->validated_exposure_version], $records);
    }

    /** Dashboard returns only scheduling metadata; it never implies publication authorization.
     * @param list<SponsorshipDeliverable> $records
     * @return list<array<string,mixed>> */
    public function dashboard(TenantContext $context, array $records): array
    {
        $labels = $this->labels($context, $records);

        return array_map(fn (SponsorshipDeliverable $r): array => [...$this->values($r, $labels), 'sponsor_id' => $r->getAttribute('sponsor_id'),
            'agreement_reference' => $r->getAttribute('agreement_reference'), 'campaign_title' => $r->getAttribute('campaign_title'), 'sponsor_name' => $r->getAttribute('sponsor_name')], $records);
    }

    /** @param list<SponsorshipDeliverable> $records
     * @return array<int,string> */
    private function labels(TenantContext $context, array $records): array
    {
        return $this->members->labels($context, array_values(array_unique(array_map(fn (SponsorshipDeliverable $r): int => $r->responsible_user_id, $records))));
    }

    /** @param array<int,string> $labels
     * @return array<string,mixed> */
    private function values(SponsorshipDeliverable $r, array $labels): array
    {
        return ['id' => $r->id, 'agreement_id' => $r->agreement_id, 'campaign_id' => $r->campaign_id, 'exposure_id' => $r->exposure_id,
            'kind' => $r->kind, 'title' => $r->title, 'responsible_user_id' => $r->responsible_user_id, 'responsible_label' => $labels[$r->responsible_user_id] ?? null,
            'due_at' => $r->due_at->toIso8601String(), 'status' => $r->status, 'version' => $r->version, 'prepared_by' => $r->prepared_by,
            'delivered_at' => $r->delivered_at?->toIso8601String(), 'delivered_by' => $r->delivered_by,
            'validated_at' => $r->validated_at?->toIso8601String(), 'validated_by' => $r->validated_by,
            'overdue' => $r->status === 'promised' && $r->due_at->isPast(), 'delivered_late' => $r->delivered_at !== null && $r->delivered_at->greaterThan($r->due_at),
            'publication_status' => 'private'];
    }
}
