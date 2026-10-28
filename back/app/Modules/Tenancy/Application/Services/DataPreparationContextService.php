<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\DataPreparationContext;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Support\Str;

final readonly class DataPreparationContextService implements DataPreparationContext
{
    public function __construct(private TenantResolver $resolver, private OrganizationAccessService $access) {}

    public function resolve(string $organization, int $actor): TenantContext
    {
        $context = $this->resolver->resolve($organization, '', $actor, (string) Str::uuid());
        $record = $this->access->find($organization, $actor);
        $this->access->requireAdministration($record, $actor);

        return $context;
    }
}
