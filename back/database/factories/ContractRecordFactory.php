<?php

namespace Database\Factories;

use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContractRecord> */
class ContractRecordFactory extends Factory
{
    protected $model = ContractRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['responsible_person_id' => Person::factory()->withAdministrativeIdentity(),
            'organization_id' => fn (array $attributes): string => Person::query()->whereKey($attributes['responsible_person_id'])->firstOrFail()->organization_id,
            'reference' => 'contrat-'.Str::lower(Str::random(12)), 'type' => 'player', 'title' => 'Dossier joueur',
            'responsible_name' => 'Camille Martin', 'starts_on' => null, 'ends_on' => null, 'status' => 'draft',
            'version' => (string) Str::uuid(), 'created_by' => User::factory(), 'prepared_by' => fn (array $attributes): int => $attributes['created_by'],
            'approved_by' => null, 'approved_at' => null, 'signed_on' => null, 'signature_reference' => null,
            'signature_recorded_at' => null, 'terminated_on' => null];
    }
}
