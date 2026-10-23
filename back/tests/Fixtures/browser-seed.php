<?php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

$application = require __DIR__.'/browser-application.php';
if (! $application instanceof Application) {
    throw new RuntimeException('Laravel application unavailable.');
}
$kernel = $application->make(Kernel::class);
$kernel->bootstrap();
$database = config('database.connections.sqlite.database');
if (! is_string($database) || filesize($database) !== 0 || config('database.default') !== 'sqlite'
    || ! $application->environment('testing')) {
    throw new RuntimeException('An empty isolated SQLite database is required.');
}
if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Browser database migration failed.');
}

$user = User::factory()->withMfa()->create([
    'name' => 'Browser staff', 'email' => 'browser-staff@example.test',
    'password' => 'Browser-fixture-only-2026!',
]);
$alpha = Organization::factory()->create(['name' => 'Browser Alpha', 'slug' => 'browser-alpha']);
$beta = Organization::factory()->create(['name' => 'Browser Beta', 'slug' => 'browser-beta']);
$foreign = Organization::factory()->create(['name' => 'Browser Foreign', 'slug' => 'browser-foreign']);
foreach ([[$alpha, ['sports.read', 'sports.write']], [$beta, ['sports.read']]] as [$organization, $permissions]) {
    $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    $role = OrganizationRole::factory()->create([
        'organization_id' => $organization->id, 'permissions' => $permissions,
        'privileged' => in_array('sports.write', $permissions, true),
    ]);
    OrganizationRoleAssignment::factory()->create([
        'organization_id' => $organization->id, 'user_id' => $user->id,
        'membership_id' => $membership->id, 'role_id' => $role->id, 'scope_id' => $organization->id,
    ]);
}
$betaPerson = Person::factory()->create(['organization_id' => $beta->id, 'pseudonym' => 'Beta private player']);
$foreignPerson = Person::factory()->create(['organization_id' => $foreign->id, 'pseudonym' => 'Foreign private player']);
echo json_encode([
    'alpha' => $alpha->id, 'beta' => $beta->id, 'foreign' => $foreign->id,
    'betaPerson' => $betaPerson->id, 'foreignPerson' => $foreignPerson->id,
], JSON_THROW_ON_ERROR);
