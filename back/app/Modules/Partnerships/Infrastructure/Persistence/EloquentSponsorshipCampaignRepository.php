<?php

namespace App\Modules\Partnerships\Infrastructure\Persistence;

use App\Modules\Partnerships\Domain\Contracts\SponsorshipCampaignRepository;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaignEvent;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class EloquentSponsorshipCampaignRepository implements SponsorshipCampaignRepository
{
    public function dashboardCount(string $org, string $now): int
    {
        return $this->dashboardQuery($org)->where('sponsorship_deliverables.status', 'promised')->where('due_at', '<', $now)->count();
    }

    public function campaigns(string $org, string $agreement, array $filters): LengthAwarePaginator
    {
        return SponsorshipCampaign::query()->where('organization_id', $org)->where('agreement_id', $agreement)
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderByDesc('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function campaign(string $org, string $agreement, string $id, bool $lock = false): SponsorshipCampaign
    {
        $q = SponsorshipCampaign::query()->where('organization_id', $org)->where('agreement_id', $agreement);

        return ($lock ? $q->lockForUpdate() : $q)->findOrFail(strtolower($id));
    }

    public function saveCampaign(?SponsorshipCampaign $record, array $attributes): SponsorshipCampaign
    {
        $record ??= new SponsorshipCampaign;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function deliverables(string $org, string $campaign, array $filters): LengthAwarePaginator
    {
        return SponsorshipDeliverable::query()->where('organization_id', $org)->where('campaign_id', $campaign)
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderBy('due_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function deliverable(string $org, string $campaign, string $id): SponsorshipDeliverable
    {
        return SponsorshipDeliverable::query()->where('organization_id', $org)->where('campaign_id', $campaign)->lockForUpdate()->findOrFail(strtolower($id));
    }

    public function saveDeliverable(?SponsorshipDeliverable $record, array $attributes): SponsorshipDeliverable
    {
        $record ??= new SponsorshipDeliverable;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function hasPending(string $org, string $campaign): bool
    {
        return SponsorshipDeliverable::query()->where('organization_id', $org)->where('campaign_id', $campaign)->whereIn('status', ['promised', 'delivered'])->exists();
    }

    public function hasCampaignOutside(string $org, string $agreement, string $start, string $end): bool
    {
        return SponsorshipCampaign::query()->where('organization_id', $org)->where('agreement_id', $agreement)->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereDate('starts_on', '<', $start)->orWhereDate('ends_on', '>', $end))->exists();
    }

    public function hasActiveForAgreement(string $org, string $agreement): bool
    {
        return SponsorshipCampaign::query()->where('organization_id', $org)->where('agreement_id', $agreement)->where('status', 'active')->exists();
    }

    public function hasActiveForSponsor(string $org, string $sponsor): bool
    {
        return SponsorshipCampaign::query()->where('sponsorship_campaigns.organization_id', $org)->where('sponsorship_campaigns.status', 'active')
            ->join('sponsorship_agreements as a', fn ($join) => $join->on('a.id', '=', 'sponsorship_campaigns.agreement_id')->on('a.organization_id', '=', 'sponsorship_campaigns.organization_id'))
            ->where('a.sponsor_id', $sponsor)->exists();
    }

    public function hasDueOutside(string $org, string $campaign, string $start, string $end): bool
    {
        return SponsorshipDeliverable::query()->where('organization_id', $org)->where('campaign_id', $campaign)
            ->where(fn (Builder $q) => $q->where('due_at', '<', $start)->orWhere('due_at', '>=', $end))->exists();
    }

    public function exposures(string $org, string $agreement, array $ids): array
    {
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Exposure lookup requires a bounded page.');
        }

        return array_values(SponsorshipExposure::query()->where('organization_id', $org)->where('agreement_id', $agreement)->whereIn('id', $ids)->get()->all());
    }

    public function event(array $attributes): void
    {
        (new SponsorshipCampaignEvent)->forceFill($attributes)->save();
    }

    public function history(string $org, string $campaign, array $filters): LengthAwarePaginator
    {
        return SponsorshipCampaignEvent::query()->where('organization_id', $org)->where('campaign_id', $campaign)->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /**
     * @return Builder<SponsorshipDeliverable> */
    private function dashboardQuery(string $org): Builder
    {
        return SponsorshipDeliverable::query()->where('sponsorship_deliverables.organization_id', $org)
            ->join('sponsorship_campaigns as c', fn ($join) => $join->on('c.id', '=', 'sponsorship_deliverables.campaign_id')->on('c.organization_id', '=', 'sponsorship_deliverables.organization_id'))
            ->where('c.status', 'active');
    }

    public function summary(string $org, string $now): array
    {
        $rows = $this->dashboardQuery($org)->selectRaw('sponsorship_deliverables.status, COUNT(*) AS aggregate')->groupBy('sponsorship_deliverables.status')->toBase()->get();
        $counts = array_fill_keys(['promised', 'delivered', 'validated', 'cancelled'], 0);
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->aggregate;
        }
        $counts['overdue'] = $this->dashboardQuery($org)->where('sponsorship_deliverables.status', 'promised')->where('due_at', '<', $now)->count();

        return $counts;
    }

    public function overdue(string $org, string $now, array $filters): LengthAwarePaginator
    {
        return $this->dashboardQuery($org)->where('sponsorship_deliverables.status', 'promised')->where('due_at', '<', $now)
            ->join('sponsorship_agreements as a', fn ($join) => $join->on('a.id', '=', 'sponsorship_deliverables.agreement_id')->on('a.organization_id', '=', 'sponsorship_deliverables.organization_id'))
            ->join('sponsors as s', fn ($join) => $join->on('s.id', '=', 'a.sponsor_id')->on('s.organization_id', '=', 'a.organization_id'))
            ->select(['sponsorship_deliverables.*', 'a.sponsor_id', 'a.reference as agreement_reference', 'c.title as campaign_title', 's.name as sponsor_name'])
            ->orderBy('due_at')->orderBy('sponsorship_deliverables.id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
