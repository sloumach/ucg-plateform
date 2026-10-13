<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;

interface EligibilityPeopleDirectory
{
    /** @param list<string> $personIds
     * @return array<string,array{age_status:string,profiles:list<array{id:string,version:string,region_key:string,rank:string|null,observed_at:string|null}>}>
     */
    public function facts(TenantContext $context, array $personIds, string $gameKey, ?string $platform, CarbonImmutable $at, ?int $minAge, ?int $maxAge): array;
}
