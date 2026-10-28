<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Application\Contracts\DataPreparationReferences;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

final readonly class EloquentDataPreparationReferences implements DataPreparationReferences
{
    private const array FIELDS = ['person_id' => 'id', 'person_pseudonym' => 'pseudonym'];

    public function __construct(private TenantAuthorization $authorization) {}

    public function lookup(TenantContext $context, array $checks): array
    {
        if (! $context->canAdminister()) {
            throw new AuthorizationException;
        }
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
            $query = Person::query()->where('organization_id', $context->organizationId);
            if ($column === 'pseudonym') {
                $query->withTrashed();
            }
            if ($column === 'pseudonym') {
                $values = array_map(mb_strtolower(...), $values);
                $query->selectRaw('LOWER(pseudonym) as audit_key')->distinct();
                $found = $query->whereIn(new Expression('LOWER(pseudonym)'), $values)->limit(500)->pluck('audit_key')->all();
            } else {
                $found = $query->whereIn($column, $values)->distinct()->limit(500)->pluck($column)->all();
            }
            foreach ($group as $position => $check) {
                $result[$position] = in_array($column === 'pseudonym' ? mb_strtolower($check['value']) : $check['value'], $found, true);
            }
        }
        if (count($result) !== count($checks)) {
            throw new InvalidArgumentException('Unknown reference kind.');
        }

        return $result;
    }
}
