<?php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
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
$rosterUser = User::factory()->withMfa()->create([
    'name' => 'Browser roster staff', 'email' => 'browser-roster@example.test', 'password' => 'Browser-fixture-only-2026!',
]);
$rosterAlpha = Organization::factory()->create(['name' => 'Roster Alpha', 'slug' => 'roster-alpha']);
$rosterBeta = Organization::factory()->create(['name' => 'Roster Beta', 'slug' => 'roster-beta']);
foreach ([
    [$user, $alpha, ['sports.read', 'sports.write']], [$user, $beta, ['sports.read']],
    [$rosterUser, $rosterAlpha, ['sports.read', 'sports.write', 'teams.manage']], [$rosterUser, $rosterBeta, ['sports.read']],
] as [$account, $organization, $permissions]) {
    $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $account->id]);
    $role = OrganizationRole::factory()->create([
        'organization_id' => $organization->id, 'permissions' => $permissions,
        'privileged' => in_array('sports.write', $permissions, true),
    ]);
    OrganizationRoleAssignment::factory()->create([
        'organization_id' => $organization->id, 'user_id' => $account->id,
        'membership_id' => $membership->id, 'role_id' => $role->id, 'scope_id' => $organization->id,
    ]);
}
$betaPerson = Person::factory()->create(['organization_id' => $beta->id, 'pseudonym' => 'Beta private player']);
$foreignPerson = Person::factory()->create(['organization_id' => $foreign->id, 'pseudonym' => 'Foreign private player']);
$rosterBetaPerson = Person::factory()->create(['organization_id' => $rosterBeta->id, 'pseudonym' => 'Beta roster player', 'status' => 'active']);
$rosterBetaTeam = Team::factory()->create(['organization_id' => $rosterBeta->id, 'name' => 'Beta roster team']);
$rosterBetaMembership = TeamMembership::factory()->create(['organization_id' => $rosterBeta->id, 'team_id' => $rosterBetaTeam->id, 'person_id' => $rosterBetaPerson->id]);
$foreignTeam = Team::factory()->create(['organization_id' => $foreign->id, 'name' => 'Foreign roster team']);
echo json_encode([
    'alpha' => $alpha->id, 'beta' => $beta->id, 'foreign' => $foreign->id,
    'betaPerson' => $betaPerson->id, 'foreignPerson' => $foreignPerson->id,
    'roster' => ['alpha' => $rosterAlpha->id, 'beta' => $rosterBeta->id, 'betaTeam' => $rosterBetaTeam->id,
        'betaPerson' => $rosterBetaPerson->id, 'foreignTeam' => $foreignTeam->id,
        'betaMembership' => $rosterBetaMembership->id,
        'game' => GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail()->id],
], JSON_THROW_ON_ERROR);
