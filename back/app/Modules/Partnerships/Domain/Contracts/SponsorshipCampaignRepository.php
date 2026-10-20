<?php

namespace App\Modules\Partnerships\Domain\Contracts;

use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaignEvent;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use Illuminate\Pagination\LengthAwarePaginator;

interface SponsorshipCampaignRepository
{
    public function dashboardCount(string $org, string $now): int;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,SponsorshipCampaign> */
    public function campaigns(string $org, string $agreement, array $filters): LengthAwarePaginator;

    public function campaign(string $org, string $agreement, string $id, bool $lock = false): SponsorshipCampaign;

    /** @param array<string,mixed> $attributes */
    public function saveCampaign(?SponsorshipCampaign $record, array $attributes): SponsorshipCampaign;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,SponsorshipDeliverable> */
    public function deliverables(string $org, string $campaign, array $filters): LengthAwarePaginator;

    public function deliverable(string $org, string $campaign, string $id): SponsorshipDeliverable;

    /** @param array<string,mixed> $attributes */
    public function saveDeliverable(?SponsorshipDeliverable $record, array $attributes): SponsorshipDeliverable;

    public function hasPending(string $org, string $campaign): bool;

    public function hasCampaignOutside(string $org, string $agreement, string $start, string $end): bool;

    public function hasActiveForAgreement(string $org, string $agreement): bool;

    public function hasActiveForSponsor(string $org, string $sponsor): bool;

    public function hasDueOutside(string $org, string $campaign, string $start, string $end): bool;

    /** @param list<string> $ids
     * @return list<SponsorshipExposure> */
    public function exposures(string $org, string $agreement, array $ids): array;

    /** @param array<string,mixed> $attributes */
    public function event(array $attributes): void;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,SponsorshipCampaignEvent> */
    public function history(string $org, string $campaign, array $filters): LengthAwarePaginator;

    /**
     * @return array<string,int> */
    public function summary(string $org, string $now): array;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,SponsorshipDeliverable> */
    public function overdue(string $org, string $now, array $filters): LengthAwarePaginator;
}
