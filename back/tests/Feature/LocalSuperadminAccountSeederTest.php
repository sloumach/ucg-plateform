<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use Database\Seeders\LocalSuperadminAccountSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class LocalSuperadminAccountSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seed_creates_a_verified_local_account_and_preserves_it_on_rerun(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        $password = Str::random(32);
        $command = $this->artisan('db:seed', ['--class' => LocalSuperadminAccountSeeder::class]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsQuestion('Mot de passe du compte super admin local (16 à 72 octets)', $password)->assertSuccessful();

        $this->seed(LocalSuperadminAccountSeeder::class);

        $account = User::query()->where('email', 'superadmin@ucg.local')->sole();
        $this->assertTrue($account->hasVerifiedEmail());
        $this->assertTrue(Hash::check($password, $account->password));
        $this->assertFalse($account->hasMfa());
        $this->assertDatabaseCount('platform_roles', 0);
    }

    public function test_seed_creates_no_account_outside_local(): void
    {
        $this->seed(LocalSuperadminAccountSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
