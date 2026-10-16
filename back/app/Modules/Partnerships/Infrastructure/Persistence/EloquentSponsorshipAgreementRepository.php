<?php

namespace App\Modules\Partnerships\Infrastructure\Persistence;

use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreementEvent;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreementTerms;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class EloquentSponsorshipAgreementRepository implements SponsorshipAgreementRepository
{
    public function paginate(string $org, string $sponsor, array $filters): LengthAwarePaginator
    {
        $query = SponsorshipAgreement::query()->where('organization_id', $org)->where('sponsor_id', strtolower($sponsor));
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['q'])) {
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['q'])));
            $query->where(fn (Builder $query) => $query->whereRaw("LOWER(reference) LIKE ? ESCAPE '!'", [$prefix.'%'])
                ->orWhereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$prefix.'%']));
        }

        return $query->orderBy('reference')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $sponsor, string $id, bool $lock = false): SponsorshipAgreement
    {
        $query = SponsorshipAgreement::query()->where('organization_id', $org)->where('sponsor_id', strtolower($sponsor));

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?SponsorshipAgreement $record, array $attributes): SponsorshipAgreement
    {
        $record ??= new SponsorshipAgreement;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function exposures(string $org, string $agreement, array $filters): LengthAwarePaginator
    {
        return SponsorshipExposure::query()->where('organization_id', $org)->where('agreement_id', $agreement)
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->orderBy('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function activeExposures(string $org, string $agreement): array
    {
        return array_values(SponsorshipExposure::query()->where('organization_id', $org)->where('agreement_id', $agreement)->where('status', 'active')
            ->orderBy('id')->limit(101)->get()->all());
    }

    public function exposure(string $org, string $agreement, string $id): SponsorshipExposure
    {
        return SponsorshipExposure::query()->where('organization_id', $org)->where('agreement_id', $agreement)->lockForUpdate()->findOrFail(strtolower($id));
    }

    public function saveExposure(?SponsorshipExposure $record, array $attributes): SponsorshipExposure
    {
        $record ??= new SponsorshipExposure;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function exposureCount(string $org, string $agreement): int
    {
        return SponsorshipExposure::query()->where('organization_id', $org)->where('agreement_id', $agreement)->count();
    }

    public function terms(string $org, string $agreement): ?SponsorshipAgreementTerms
    {
        return SponsorshipAgreementTerms::query()->where('organization_id', $org)->where('agreement_id', $agreement)->first();
    }

    public function saveTerms(?SponsorshipAgreementTerms $record, array $attributes): SponsorshipAgreementTerms
    {
        $record ??= new SponsorshipAgreementTerms;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function hasExclusiveConflict(SponsorshipAgreement $agreement, array $keys): bool
    {
        if ($keys === []) {
            return false;
        }

        // Conservative reservation: a category is exclusive across the tenant for overlapping inclusive dates.
        return SponsorshipExposure::query()->where('sponsorship_exposures.organization_id', $agreement->organization_id)
            ->where('sponsorship_exposures.status', 'active')->whereIn('exclusivity_key', $keys)
            ->join('sponsorship_agreements as a', function (JoinClause $join): void {
                $join->on('a.id', '=', 'sponsorship_exposures.agreement_id')->on('a.organization_id', '=', 'sponsorship_exposures.organization_id');
            })->where('a.id', '!=', $agreement->id)->where('a.status', 'approved')
            ->whereDate('a.starts_on', '<=', $agreement->ends_on->format('Y-m-d'))->whereDate('a.ends_on', '>=', $agreement->starts_on->format('Y-m-d'))->exists();
    }

    public function event(array $attributes): void
    {
        (new SponsorshipAgreementEvent)->forceFill($attributes)->save();
    }

    public function history(string $org, string $agreement, array $filters): LengthAwarePaginator
    {
        return SponsorshipAgreementEvent::query()->where('organization_id', $org)->where('agreement_id', $agreement)
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
