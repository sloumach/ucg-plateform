<?php

namespace App\Modules\Performance\Infrastructure\Persistence;

use App\Modules\Performance\Application\Contracts\DataPreparationReferences;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class EloquentDataPreparationReferences implements DataPreparationReferences
{
    private const array FIELDS = ['metric_id' => 'id'];

    public function __construct(private TenantAuthorization $authorization) {}

    public function lookup(TenantContext $context, array $checks): array
    {
        if (! $context->canAdminister()) {
            throw new AuthorizationException;
        }
        $this->authorization->authorize($context, 'sports.statistics.read');
        $this->authorization->authorize($context, 'sports.read');
        if (count($checks) > 500) {
            throw new InvalidArgumentException('Reference audit limit.');
        }
        $result = [];
        foreach (self::FIELDS as $kind => $column) {
            $group = array_filter($checks, static fn (array $check): bool => $check['kind'] === $kind);
            if ($group === []) {
                continue;
            }
            $values = array_column($group, 'value');
            $query = MetricDefinition::query();

            $found = $query->whereIn($column, $values)->distinct()->limit(500)->pluck($column)->all();
            foreach ($group as $position => $check) {
                $result[$position] = in_array($check['value'], $found, true);
            }
        }
        if (count($result) !== count($checks)) {
            throw new InvalidArgumentException('Unknown reference kind.');
        }

        return $result;
    }
}
