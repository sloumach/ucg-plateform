<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\AdministrativePeopleDirectory;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LogicException;

final readonly class AdministrativePeopleService implements AdministrativePeopleDirectory
{
    public function __construct(private PersonRepository $people, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function labels(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'administrative.read');
        if (count($ids) > 11 || $this->database->transactionLevel() < 1 || array_filter($ids, fn (string $id): bool => ! Str::isUuid($id)) !== []) {
            throw new LogicException('Administrative references require a bounded tenant transaction.');
        }
        $ids = array_values(array_unique(array_map(strtolower(...), $ids)));
        $records = $this->people->contractPeople($context->organizationId, $ids);
        if (count($records) !== count($ids)) {
            throw new ModelNotFoundException;
        }
        $result = [];
        foreach ($records as $person) {
            $result[$person->id] = $this->label($person);
        }

        return $result;
    }

    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'administrative.read');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new LogicException('Administrative references must be paginated.');
        }
        $result = $this->people->contractPeoplePage($context->organizationId, $page, $perPage);
        $result->setCollection($result->getCollection()->map(fn (Person $person): array => ['id' => $person->id, 'label' => $this->label($person)]));

        return $result;
    }

    private function label(Person $person): string
    {
        $name = trim(($person->first_name ?? '').' '.($person->last_name ?? ''));

        return $name === '' ? __('managed_contracts.unnamed', ['reference' => substr($person->id, -8)]) : $name;
    }
}
