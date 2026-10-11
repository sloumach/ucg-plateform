<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\PersonRecord;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;

final readonly class PersonProjectionService
{
    public function __construct(private TenantDataVisibility $visibility) {}

    public function project(TenantContext $context, Person $person): PersonRecord
    {
        $fields = [];
        $data = ['id' => $person->id, 'version' => $person->version];
        foreach (['id', 'version'] as $name) {
            $fields[$name] = new FieldVisibility('organization.view');
        }
        foreach (PersonInput::SPORTS_FIELDS as $name) {
            $fields[$name] = new FieldVisibility('sports.read');
            $data[$name] = $name === 'status' ? $person->status->value : $person->getAttribute($name);
        }
        foreach (PersonInput::ADMINISTRATIVE_FIELDS as $name) {
            $fields[$name] = new FieldVisibility('administrative.read');
            $data[$name] = $person->getAttribute($name);
        }

        return new PersonRecord($this->visibility->project($context, new TenantDataRecord($person->organization_id, $data), $fields));
    }
}
