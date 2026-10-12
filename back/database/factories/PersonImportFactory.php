<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<PersonImport> */
class PersonImportFactory extends Factory
{
    protected $model = PersonImport::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'actor_user_id' => User::factory(),
            'artifact_id' => function (array $attributes): string {
                $id = (string) Str::uuid();
                DB::table('tenant_artifacts')->insert(['id' => $id, 'organization_id' => $attributes['organization_id'],
                    'name' => 'fixture.csv.enc', 'disk' => 'local', 'bytes' => 0, 'ready' => true]);

                return $id;
            }, 'fingerprint' => hash('sha256', (string) Str::uuid()),
            'delimiter' => ',', 'headers' => ['pseudo'], 'mapping' => [], 'defaults' => [],
            'row_count' => 1, 'status' => 'uploaded', 'version' => (string) Str::uuid()];
    }
}
