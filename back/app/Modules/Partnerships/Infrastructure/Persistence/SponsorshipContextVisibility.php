<?php

namespace App\Modules\Partnerships\Infrastructure\Persistence;

use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

final class SponsorshipContextVisibility
{
    /** @param array<string, Query|null> $sources
     * @return Builder<SponsorshipExposure> */
    public function exposures(string $org, array $sources): Builder
    {
        return SponsorshipExposure::query()->where('organization_id', $org)->where(fn (Builder $scope) => $scope
            ->whereDoesntHave('context')->orWhereHas('context', function (Builder $binding) use ($org, $sources): void {
                $binding->where('organization_id', $org)->where(function (Builder $allowed) use ($sources): void {
                    $allowed->whereRaw('1 = 0');
                    foreach (['activity_id', 'media_id'] as $column) {
                        if (($sources[$column] ?? null) !== null) {
                            $allowed->orWhereIn($column, clone $sources[$column]);
                        }
                    }
                });
            }));
    }
}
