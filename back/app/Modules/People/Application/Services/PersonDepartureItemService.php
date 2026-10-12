<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Data\PersonLifecycleData;
use App\Modules\People\Application\Validation\PersonLifecycleInput as Input;
use App\Modules\People\Domain\Contracts\PersonLifecycleRepository;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PersonDepartureItemService
{
    public function __construct(private PersonLifecycleRepository $records, private PersonRepository $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private PersonLifecycleProjectionService $projection, private ConnectionInterface $database, private Factory $validator) {}

    /** @return LengthAwarePaginator<int,PersonDepartureItem> */
    public function list(TenantContext $context, string $personId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->validator->make(['per_page' => $perPage, 'page' => $page], Input::pageRules(), Input::messages())->validate();
        $this->people->find($context->organizationId, $personId);

        return $this->records->items($context->organizationId, $personId, $perPage, $page);
    }

    public function pending(TenantContext $context, string $personId): int
    {
        $this->authorization->authorize($context, 'administrative.read');

        return $this->records->itemCount($context->organizationId, $personId, true);
    }

    public function save(TenantContext $context, string $personId, PersonLifecycleData $data, ?string $id = null, ?string $ip = null): PersonDepartureItem
    {
        $attributes = Input::validate($this->validator, $data, Input::itemRules($id === null), $id === null);
        $this->authorization->authorize($context, 'administrative.write');

        return $this->database->transaction(function () use ($context, $personId, $data, $attributes, $id, $ip): PersonDepartureItem {
            $this->guard->lock($context);
            $this->authorization->authorize($context, 'administrative.write');
            $this->people->find($context->organizationId, $personId, true);
            $item = $id === null ? null : $this->records->findItem($context->organizationId, $personId, $id);
            if ($item !== null && $item->version !== $data->version) {
                throw new DomainConflictException(__('people_lifecycle.stale'), 'PERSON_DEPARTURE_ITEM_VERSION_STALE');
            }
            if ($item === null && $this->records->itemCount($context->organizationId, $personId) >= Input::MAX_ITEMS) {
                throw new DomainConflictException(__('people_lifecycle.limit'), 'PERSON_DEPARTURE_ITEM_LIMIT_REACHED');
            }
            $status = $attributes['status'] ?? $item->status ?? 'open';
            $notes = array_key_exists('notes', $attributes) ? $attributes['notes'] : $item?->notes;
            if ($status === 'waived' && (! is_string($notes) || trim($notes) === '')) {
                throw ValidationException::withMessages(['notes' => __('people_lifecycle.waiver')]);
            }
            $values = [...$attributes, 'status' => $status, 'completed_at' => $status === 'open' ? null : ($item->completed_at ?? CarbonImmutable::now()),
                'version' => (string) Str::uuid()];
            if (array_key_exists('due_at', $attributes)) {
                $values['due_at'] = Input::date($attributes['due_at']);
            }
            $before = $item === null ? [] : $this->projection->auditItem($item);
            $saved = $this->records->saveItem($context->organizationId, $personId, $item, $values);
            $this->projection->audit($context, $before, [...$this->projection->auditItem($saved), 'departure_item_changed_fields' => array_keys($attributes)], $ip);

            return $saved;
        });
    }
}
