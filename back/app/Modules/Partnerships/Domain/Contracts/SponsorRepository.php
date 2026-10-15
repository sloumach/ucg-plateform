<?php

namespace App\Modules\Partnerships\Domain\Contracts;

use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorContact;
use App\Modules\Partnerships\Domain\Models\SponsorshipInteraction;
use App\Modules\Partnerships\Domain\Models\SponsorshipOpportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SponsorRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Sponsor>
     */
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator;

    public function find(string $organizationId, string $id, bool $lock = false): Sponsor;

    /** @param array<string, mixed> $attributes */
    public function save(?Sponsor $sponsor, array $attributes): Sponsor;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SponsorContact>|LengthAwarePaginator<int, SponsorshipOpportunity>|LengthAwarePaginator<int, SponsorshipInteraction>
     */
    public function related(string $organizationId, string $sponsorId, string $kind, array $filters): LengthAwarePaginator;

    public function findRelated(string $organizationId, string $sponsorId, string $kind, string $id): SponsorContact|SponsorshipOpportunity;

    /** @param array<string, mixed> $attributes */
    public function saveRelated(string $kind, SponsorContact|SponsorshipOpportunity|null $record, array $attributes): SponsorContact|SponsorshipOpportunity|SponsorshipInteraction;
}
