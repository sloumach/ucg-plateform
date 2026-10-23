<?php

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

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
$calendarUser = User::factory()->withMfa()->create([
    'name' => 'Browser calendar staff', 'email' => 'browser-calendar@example.test', 'password' => 'Browser-fixture-only-2026!',
]);
$calendarAlpha = Organization::factory()->create(['name' => 'Calendar Alpha', 'slug' => 'calendar-alpha']);
$calendarBeta = Organization::factory()->create(['name' => 'Calendar Beta', 'slug' => 'calendar-beta']);
$matchUser = User::factory()->withMfa()->create([
    'name' => 'Browser match staff', 'email' => 'browser-match@example.test', 'password' => 'Browser-fixture-only-2026!',
]);
$matchReviewer = User::factory()->withMfa()->create([
    'name' => 'Browser match reviewer', 'email' => 'browser-reviewer@example.test', 'password' => 'Browser-fixture-only-2026!',
]);
$matchAlpha = Organization::factory()->create(['name' => 'Match Alpha', 'slug' => 'match-alpha']);
$matchBeta = Organization::factory()->create(['name' => 'Match Beta', 'slug' => 'match-beta']);
foreach ([
    [$user, $alpha, ['sports.read', 'sports.write']], [$user, $beta, ['sports.read']],
    [$rosterUser, $rosterAlpha, ['sports.read', 'sports.write', 'teams.manage']], [$rosterUser, $rosterBeta, ['sports.read']],
    [$calendarUser, $calendarAlpha, ['calendar.read', 'calendar.write', 'availability.read']], [$calendarUser, $calendarBeta, ['calendar.read']],
    [$matchUser, $matchAlpha, ['sports.read', 'sports.write', 'sports.correct', 'sports.validate']], [$matchUser, $matchBeta, ['sports.read']],
    [$matchReviewer, $matchAlpha, ['sports.read', 'sports.validate']],
] as [$account, $organization, $permissions]) {
    $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $account->id]);
    $role = OrganizationRole::factory()->create([
        'organization_id' => $organization->id, 'permissions' => $permissions,
        'privileged' => array_diff($permissions, ['sports.read']) !== [],
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
$calendarDay = now()->addDays(2)->toDateString();
$calendarPerson = Person::factory()->create(['organization_id' => $calendarAlpha->id, 'pseudonym' => 'Calendar player A', 'status' => 'active']);
$calendarSecondPerson = Person::factory()->create(['organization_id' => $calendarAlpha->id, 'pseudonym' => 'Calendar player B', 'status' => 'active']);
$calendarTeam = Team::factory()->create(['organization_id' => $calendarAlpha->id, 'name' => 'Calendar team']);
Availability::factory()->create([
    'organization_id' => $calendarAlpha->id, 'person_id' => $calendarPerson->id, 'created_by' => $calendarUser->id,
    'status' => 'unavailable', 'comment' => 'PRIVATE_CALENDAR_AVAILABILITY_NOTE', 'origin_timezone' => 'UTC',
    'starts_at' => $calendarDay.' 10:00:00', 'ends_at' => $calendarDay.' 11:00:00',
]);
$calendarBetaActivity = CalendarActivity::factory()->create([
    'organization_id' => $calendarBeta->id, 'created_by' => $calendarUser->id, 'title' => 'Beta calendrier privé',
    'starts_at' => $calendarDay.' 12:00:00', 'ends_at' => $calendarDay.' 13:00:00',
]);
$calendarForeignActivity = CalendarActivity::factory()->create([
    'organization_id' => $foreign->id, 'created_by' => $foreign->owner_user_id, 'title' => 'Foreign calendrier privé',
    'starts_at' => $calendarDay.' 12:00:00', 'ends_at' => $calendarDay.' 13:00:00',
]);
$matchDay = now()->addDays(3)->toDateString();
$matchResponsible = Person::factory()->create(['organization_id' => $matchAlpha->id, 'user_id' => $matchReviewer->id, 'pseudonym' => 'Match reviewer', 'status' => 'active']);
$matchTeams = [];
foreach (['A', 'B'] as $index => $suffix) {
    $team = Team::factory()->create(['organization_id' => $matchAlpha->id, 'name' => 'Match team '.$suffix]);
    $person = $index === 0 ? $matchResponsible : Person::factory()->create(['organization_id' => $matchAlpha->id, 'pseudonym' => 'Match player B', 'status' => 'active']);
    $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => now()->subDay()->startOfDay()]);
    $lineup = TeamLineup::factory()->create(['team_id' => $team->id, 'label' => 'Match lineup '.$suffix, 'effective_at' => $matchDay.' 10:00:00']);
    TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $period->id, 'pseudonym' => $person->pseudonym]);
    $lineup->forceFill(['status' => 'finalized', 'version' => (string) Str::uuid(), 'finalized_at' => now(), 'finalized_by' => $matchUser->id])->save();
    $matchTeams[] = ['team' => $team->id, 'lineup' => $lineup->id];
}
$matchBetaRecord = MatchRecord::factory()->create(['organization_id' => $matchBeta->id, 'created_by' => $matchUser->id, 'title' => 'Beta match privé']);
$matchForeignRecord = MatchRecord::factory()->create(['organization_id' => $foreign->id, 'title' => 'Foreign match privé']);
echo json_encode([
    'alpha' => $alpha->id, 'beta' => $beta->id, 'foreign' => $foreign->id,
    'betaPerson' => $betaPerson->id, 'foreignPerson' => $foreignPerson->id,
    'roster' => ['alpha' => $rosterAlpha->id, 'beta' => $rosterBeta->id, 'betaTeam' => $rosterBetaTeam->id,
        'betaPerson' => $rosterBetaPerson->id, 'foreignTeam' => $foreignTeam->id,
        'betaMembership' => $rosterBetaMembership->id,
        'game' => GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail()->id],
    'calendar' => ['alpha' => $calendarAlpha->id, 'beta' => $calendarBeta->id, 'day' => $calendarDay,
        'person' => $calendarPerson->id, 'secondPerson' => $calendarSecondPerson->id, 'team' => $calendarTeam->id,
        'betaActivity' => $calendarBetaActivity->id, 'foreignActivity' => $calendarForeignActivity->id],
    'match' => ['alpha' => $matchAlpha->id, 'beta' => $matchBeta->id, 'day' => $matchDay,
        'responsible' => $matchResponsible->id, 'teams' => $matchTeams,
        'betaMatch' => $matchBetaRecord->id, 'foreignMatch' => $matchForeignRecord->id,
        'game' => GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail()->id],
], JSON_THROW_ON_ERROR);
