<?php

namespace App\Modules\Teams\Application\Contracts;

interface OrganizationGameCatalogue
{
    /** Called after tenant authorization; new business writes must use the tenant business guard first. */
    public function assertReference(string $organizationId, string $gameId, string $kind, string $key): void;
}
