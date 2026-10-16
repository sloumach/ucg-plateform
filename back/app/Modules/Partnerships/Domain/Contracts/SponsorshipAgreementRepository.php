<?php

namespace App\Modules\Partnerships\Domain\Contracts;

use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreementEvent;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreementTerms;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use Illuminate\Pagination\LengthAwarePaginator;

interface SponsorshipAgreementRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SponsorshipAgreement> */
    public function paginate(string $org, string $sponsor, array $filters): LengthAwarePaginator;

    public function find(string $org, string $sponsor, string $id, bool $lock = false): SponsorshipAgreement;

    /** @param array<string, mixed> $attributes */
    public function save(?SponsorshipAgreement $record, array $attributes): SponsorshipAgreement;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SponsorshipExposure> */
    public function exposures(string $org, string $agreement, array $filters): LengthAwarePaginator;

    /** Approval is bounded to MAX_EXPOSURES; includes only non-archived rows.
     * @return list<SponsorshipExposure> */
    public function activeExposures(string $org, string $agreement): array;

    public function exposure(string $org, string $agreement, string $id): SponsorshipExposure;

    /** @param array<string, mixed> $attributes */
    public function saveExposure(?SponsorshipExposure $record, array $attributes): SponsorshipExposure;

    public function exposureCount(string $org, string $agreement): int;

    public function terms(string $org, string $agreement): ?SponsorshipAgreementTerms;

    /** @param array<string, mixed> $attributes */
    public function saveTerms(?SponsorshipAgreementTerms $record, array $attributes): SponsorshipAgreementTerms;

    /** @param list<string> $keys */
    public function hasExclusiveConflict(SponsorshipAgreement $agreement, array $keys): bool;

    /** @param array<string, mixed> $attributes */
    public function event(array $attributes): void;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SponsorshipAgreementEvent> */
    public function history(string $org, string $agreement, array $filters): LengthAwarePaginator;
}
