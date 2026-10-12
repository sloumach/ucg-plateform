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

    /** @param list<Person> $people
     * @return list<array<string, mixed>>
     */
    public function summaries(TenantContext $context, array $people, bool $sports, bool $administrative): array
    {
        $fields = ['id' => new FieldVisibility('organization.view'), 'version' => new FieldVisibility('organization.view')];
        $names = [];
        if ($sports) {
            foreach (['pseudonym', 'language', 'timezone', 'status', 'game_profiles_count'] as $name) {
                $fields[$name] = new FieldVisibility('sports.read');
                $names[] = $name;
            }
        }
        if ($administrative) {
            foreach (['user_id', 'first_name', 'last_name', 'contact_email'] as $name) {
                $fields[$name] = new FieldVisibility('administrative.read');
                $names[] = $name;
            }
        }
        $records = [];
        foreach ($people as $person) {
            $data = ['id' => $person->id, 'version' => $person->version];
            foreach ($names as $name) {
                $data[$name] = match ($name) {
                    'status' => $person->status->value,
                    'game_profiles_count' => (int) $person->getAttribute($name),
                    default => $person->getAttribute($name),
                };
            }
            $records[] = new TenantDataRecord($person->organization_id, $data);
        }

        return $this->visibility->projectMany($context, $records, $fields);
    }

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
