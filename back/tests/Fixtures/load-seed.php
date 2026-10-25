<?php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

/** @var Application $application */
$application = require __DIR__.'/load-application.php';
$kernel = $application->make(Kernel::class);
$kernel->bootstrap();
$database = $application->make(DatabaseManager::class)->connection();
if (! $application->environment('testing') || $database->getDriverName() !== 'pgsql'
    || (int) $database->scalar("SELECT COUNT(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public'") !== 0) {
    fwrite(STDERR, 'An empty owned PostgreSQL load database is required.'.PHP_EOL);
    exit(2);
}
if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    fwrite(STDERR, 'Load database migration failed.'.PHP_EOL);
    exit(3);
}

$owner = User::factory()->withMfa()->create(['email' => 'load-owner@example.test']);
$fixture = ['profile' => 'smoke', 'organizations' => [], 'actors' => [], 'people' => [],
    'from' => now()->toDateString(), 'to' => now()->addDay()->toDateString()];
foreach (['alpha' => 500, 'beta' => 120, 'gamma' => 10] as $label => $count) {
    $organization = Organization::factory()->create([
        'owner_user_id' => $owner->id, 'name' => 'Load '.$label, 'slug' => 'load-'.$label,
    ]);
    $fixture['organizations'][$label] = $organization->id;
    $fixture['people'][$label] = Person::factory()->count($count)->create([
        'organization_id' => $organization->id, 'status' => 'active',
    ])->modelKeys();
    if ($label === 'gamma') {
        continue;
    }
    $email = 'load-'.$label.'@example.test';
    $actor = User::factory()->withMfa()->create(['email' => $email, 'password' => 'Load-fixture-only-2026!']);
    $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $actor->id]);
    $permissions = $label === 'alpha' ? ['sports.read', 'reporting.export', 'reporting.direction'] : ['sports.read'];
    $role = OrganizationRole::factory()->create([
        'organization_id' => $organization->id, 'permissions' => $permissions, 'privileged' => $label === 'alpha',
    ]);
    OrganizationRoleAssignment::factory()->create([
        'organization_id' => $organization->id, 'user_id' => $actor->id, 'membership_id' => $membership->id,
        'role_id' => $role->id, 'scope_id' => $organization->id,
    ]);
    $fixture['actors'][$label] = $email;
}
$database->statement('ANALYZE');
$fixture['counts'] = ['accounts' => User::query()->count(), 'organizations' => Organization::query()->count(),
    'people' => Person::query()->count(), 'matches' => 0, 'activeSessions' => 2];
echo json_encode($fixture, JSON_THROW_ON_ERROR);
