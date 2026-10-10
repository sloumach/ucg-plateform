<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class OrganizationCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_operator_can_provision_an_organization_without_creating_an_account(): void
    {
        $owner = User::factory()->create();
        $this->consoleCommand('organizations:provision', [
            '--name' => 'Ultra Cyber Game', '--slug' => 'ucg', '--owner' => $owner->id, '--actor' => $owner->id,
            '--timezone' => 'Africa/Tunis', '--country' => 'TN', '--reason' => 'Création du pilote',
        ])->assertSuccessful();
        $this->assertDatabaseHas('organizations', ['slug' => 'ucg', 'timezone' => 'Africa/Tunis', 'country' => 'TN']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organization_lifecycle_events', 1);
    }

    public function test_operator_command_requires_explicit_options_and_verified_accounts(): void
    {
        $owner = User::factory()->unverified()->create();
        $this->consoleCommand('organizations:provision', [
            '--name' => 'UCG', '--slug' => 'ucg', '--owner' => $owner->id, '--actor' => $owner->id,
            '--timezone' => 'UTC', '--country' => 'FR', '--reason' => 'Test',
        ])->expectsOutput('Un compte existant avec une adresse e-mail vérifiée est requis.')->assertFailed();
        $this->consoleCommand('organizations:provision')->assertFailed();
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_lifecycle_events', 0);
    }

    public function test_operator_can_suspend_with_a_reason_but_cannot_skip_to_archived(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->consoleCommand('organizations:transition', [
            'organization' => $organization->id, 'status' => 'suspended', '--actor' => $owner->id, '--reason' => 'Régularisation nécessaire',
        ])->assertSuccessful();
        $this->consoleCommand('organizations:transition', [
            'organization' => $organization->id, 'status' => 'archived', '--actor' => $owner->id, '--reason' => 'Transition invalide',
        ])->expectsOutput('Ce changement de statut n’est pas autorisé.')->assertFailed();
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('organization_lifecycle_events', ['organization_id' => $organization->id, 'reason' => 'Régularisation nécessaire']);
        $this->assertDatabaseCount('organization_lifecycle_events', 1);
    }

    public function test_local_seed_is_repeatable_and_preserves_existing_account_and_settings(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        $owner = User::factory()->create(['email' => 'admin@ucg.local', 'password' => 'custom-password']);
        $this->seed(DatabaseSeeder::class);
        $organization = Organization::query()->where('slug', 'ucg')->firstOrFail();
        $organization->name = 'Nom personnalisé';
        $organization->save();
        $passwordHash = $owner->refresh()->password;
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_settings', 1);
        $this->assertDatabaseCount('organization_lifecycle_events', 1);
        $this->assertDatabaseHas('organizations', ['slug' => 'ucg', 'name' => 'Nom personnalisé', 'owner_user_id' => $owner->id]);
        $this->assertSame($passwordHash, $owner->refresh()->password);
    }

    public function test_seed_does_not_create_demo_accounts_or_pilot_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        $this->consoleCommand('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
    }

    /** @param array<string, mixed> $parameters */
    private function consoleCommand(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
