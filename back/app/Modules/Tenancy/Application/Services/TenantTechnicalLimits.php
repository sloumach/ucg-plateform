<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantResourceLimits;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use LogicException;

final readonly class TenantTechnicalLimits implements TenantResourceLimits
{
    public function __construct(private Repository $config) {}

    public function value(TenantContext $context, string $name): int
    {
        $defaults = $this->config->get('tenancy.limits');
        $overrides = $this->config->get('tenancy.limit_overrides.'.strtolower($context->organizationId), []);
        if (! is_array($defaults) || ! array_key_exists($name, $defaults) || ! is_array($overrides)) {
            throw new LogicException('Unknown or invalid tenant technical limits.');
        }
        $value = $overrides[$name] ?? $defaults[$name];
        if (! is_int($value) || $value < 1) {
            throw new LogicException('Tenant technical limits must be positive integers.');
        }

        return $value;
    }

    public function assertWithin(TenantContext $context, string $name, int $amount): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('A measured resource amount cannot be negative.');
        }
        if ($amount > $this->value($context, $name)) {
            throw new TenantLimitExceededException;
        }
    }

    public function assertImport(TenantContext $context, int $bytes, int $rows): void
    {
        $this->assertWithin($context, 'import_bytes', $bytes);
        $this->assertWithin($context, 'import_rows', $rows);
    }

    public function assertExport(TenantContext $context, int $rows): void
    {
        $this->assertWithin($context, 'export_rows', $rows);
    }
}
