<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Database\Seeders\LocalUcgTestAccountsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LocalUcgTestAccountsSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seed_accepts_invitations_assigns_pdf_roles_and_creates_five_starters_and_two_substitutes_without_duplicates(): void
    {
        $organization = $this->prepareOrganization();
        $other = Organization::factory()->create();
        $credentials = $this->credentials();
        $existing = User::factory()->create(['email' => 'joueur@ucg.test']);
        $originalPassword = $existing->password;
        $manager = User::query()->where('email', 'manager-esports@ucg.test')->sole()->getRawOriginal();
        $originalRole = OrganizationRole::query()->where('key', 'manager')->sole()->getRawOriginal();
        $seeder = app(LocalUcgTestAccountsSeeder::class);

        $seeder->seedAccounts($credentials);
        $auditCount = DB::table('organization_access_events')->count() + DB::table('organization_audit_events')->count();
        $seeder->seedAccounts($credentials);

        $this->assertDatabaseCount('organization_invitations', 12);
        $this->assertSame(12, DB::table('organization_invitations')->where('status', 'accepted')->count());
        $this->assertSame(13, OrganizationMembership::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseCount('people', 7);
        $this->assertDatabaseCount('team_movements', 7);
        $this->assertSame(5, DB::table('team_memberships')->where('role_key', 'player')->count());
        $this->assertSame(2, DB::table('team_memberships')->where('role_key', 'substitute')->count());
        $this->assertSame(7, OrganizationRole::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(13, OrganizationRoleAssignment::query()->where('organization_id', $organization->id)->count());
        $this->assertSame($originalPassword, $existing->refresh()->password);
        $this->assertSame($manager, User::query()->where('email', 'manager-esports@ucg.test')->sole()->getRawOriginal());
        $this->assertSame($originalRole, OrganizationRole::query()->where('key', 'manager')->sole()->getRawOriginal());
        $this->assertSame($auditCount, DB::table('organization_access_events')->count() + DB::table('organization_audit_events')->count());
        $this->assertSame(0, OrganizationMembership::query()->where('organization_id', $other->id)->count());
        $this->assertSame(0, Person::query()->where('organization_id', $other->id)->count());
        $created = User::query()->where('email', 'joueur-7@ucg.test')->sole();
        $this->assertTrue(Hash::check($credentials[$created->email], $created->password));
        $this->assertFalse($created->hasMfa());
        $this->assertDatabaseHas('organization_roles', ['key' => 'joueur', 'privileged' => true]);
        $this->assertSame(10, count(OrganizationRole::query()->where('key', 'joueur')->sole()->permissions));
        $this->assertNotContains('calendar.write', OrganizationRole::query()->where('key', 'joueur')->sole()->permissions);
        $this->assertContains('calendar.conflicts.override', OrganizationRole::query()->where('key', 'direction-validation')->sole()->permissions);
    }

    public function test_seed_refuses_non_local_environment_before_touching_data(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('réservé à un environnement local');
        app(LocalUcgTestAccountsSeeder::class)->seedAccounts($this->credentials());
    }

    public function test_seed_rolls_back_when_an_existing_role_has_different_permissions(): void
    {
        $organization = $this->prepareOrganization();
        OrganizationRole::factory()->create(['organization_id' => $organization->id, 'key' => 'analyste-performance', 'permissions' => ['sports.read']]);
        $userCount = User::query()->count();
        try {
            app(LocalUcgTestAccountsSeeder::class)->seedAccounts($this->credentials());
            $this->fail('An existing role must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('diffère du PDF', $exception->getMessage());
        }

        $this->assertSame($userCount, User::query()->count());
        $this->assertDatabaseCount('organization_invitations', 0);
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('team_memberships', 0);
        $this->assertSame(['sports.read'], OrganizationRole::query()->where('key', 'analyste-performance')->sole()->permissions);
    }

    public function test_seed_requires_all_credentials_before_creating_accounts(): void
    {
        $this->prepareOrganization();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('douze comptes');
        app(LocalUcgTestAccountsSeeder::class)->seedAccounts([]);
    }

    private function prepareOrganization(): Organization
    {
        app()->detectEnvironment(fn (): string => 'local');
        Mail::fake();
        $organization = Organization::factory()->withMfaOwner()->create(['name' => 'Ultra Cyber Game', 'slug' => 'ucg']);
        $game = GameCatalogueEntry::factory()->create(['key' => 'mlbb', 'default_enabled' => true]);
        Team::factory()->create(['organization_id' => $organization->id, 'game_id' => $game->id, 'slug' => 'mlbb']);
        $manager = User::factory()->withMfa()->create(['email' => 'manager-esports@ucg.test']);
        $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $manager->id, 'starts_at' => now()->subDay()]);
        $role = OrganizationRole::factory()->create(['organization_id' => $organization->id, 'key' => 'manager', 'permissions' => ['sports.read', 'teams.manage']]);
        OrganizationRoleAssignment::factory()->create(['membership_id' => $membership->id, 'role_id' => $role->id]);

        return $organization;
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        $emails = ['joueur@ucg.test', 'analyste-performance@ucg.test', 'responsable-academie@ucg.test', 'administratif-financier@ucg.test', 'communication-partenariats@ucg.test', 'direction-validation@ucg.test'];
        for ($number = 2; $number <= 7; $number++) {
            $emails[] = 'joueur-'.$number.'@ucg.test';
        }

        return array_fill_keys($emails, Str::random(32));
    }
}
