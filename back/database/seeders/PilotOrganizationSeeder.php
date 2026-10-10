<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PilotOrganizationSeeder extends Seeder
{
    public function run(OrganizationService $organizations): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $owner = User::query()->where('email', 'admin@ucg.local')->firstOrFail();
        $organizations->ensurePilot($owner->id, new OrganizationDetailsData('Ultra Cyber Game', 'UTC', 'fr', 'FR'),
            new OrganizationAuditData($owner->id, 'Initialisation du pilote de développement.', (string) Str::uuid()));
    }
}
