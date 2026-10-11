<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformRoleManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_migration_does_not_assign_platform_roles_to_existing_owners(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();

        $this->assertFalse(app(PlatformAccountDirectory::class)->isTechnical($org->owner_user_id));
        $this->assertDatabaseCount('platform_roles', 0);
        $this->assertDatabaseCount('platform_role_events', 0);
    }

    public function test_operator_command_bootstraps_once_and_audits_the_explicit_reason(): void
    {
        $user = User::factory()->withMfa()->create();
        $this->assertSame(0, Artisan::call('identity:platform-role', ['user' => $user->id, '--bootstrap' => true, '--reason' => 'Initialisation opérateur approuvée']));
        $this->assertDatabaseHas('platform_roles', ['user_id' => $user->id, 'role' => 'superadmin', 'active' => true]);
        $this->assertDatabaseHas('platform_role_events', ['user_id' => $user->id, 'bootstrap_slot' => 1, 'reason' => 'Initialisation opérateur approuvée']);
        $second = User::factory()->withMfa()->create();

        $this->assertSame(1, Artisan::call('identity:platform-role', ['user' => $second->id, '--bootstrap' => true, '--reason' => 'Deuxième initialisation refusée']));

        $this->assertDatabaseCount('platform_roles', 1);
        $this->assertDatabaseCount('platform_role_events', 1);
    }

    public function test_superadmin_can_assign_and_revoke_support_with_audited_version_changes(): void
    {
        $admin = $this->bootstrap();
        $support = User::factory()->withMfa()->create();
        $service = app(PlatformRoleService::class);
        $role = $service->configure($support->id, PlatformRoleType::Support, true, $admin->id, 'Assistance incident autorisée', (string) Str::uuid());
        $version = $role->version;

        $role = $service->configure($support->id, PlatformRoleType::Support, false, $admin->id, 'Fin de mission assistance', (string) Str::uuid());

        $this->assertFalse($role->active);
        $this->assertNotSame($version, $role->version);
        $this->assertDatabaseCount('platform_role_events', 3);
        $this->assertDatabaseHas('platform_role_events', ['user_id' => $support->id, 'before_active' => true, 'after_active' => false]);
    }

    /** @return array<string, array{bool}> */
    public static function downgrade(): array
    {
        return ['revoke' => [false], 'demote' => [true]];
    }

    #[DataProvider('downgrade')]
    public function test_last_superadmin_cannot_be_removed(bool $demote): void
    {
        $admin = $this->bootstrap();
        try {
            app(PlatformRoleService::class)->configure($admin->id, $demote ? PlatformRoleType::Support : PlatformRoleType::Superadmin,
                $demote, $admin->id, 'Tentative retrait dernier opérateur', (string) Str::uuid());
            $this->fail('The last superadmin must remain active.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PLATFORM_LAST_SUPERADMIN', $exception->errorCode());
        }
        $this->assertDatabaseHas('platform_roles', ['user_id' => $admin->id, 'role' => 'superadmin', 'active' => true]);
        $this->assertDatabaseCount('platform_role_events', 1);
    }

    public function test_tenant_owner_cannot_assign_platform_privileges_even_from_application_service(): void
    {
        $this->bootstrap();
        $org = Organization::factory()->withMfaOwner()->create();
        $target = User::factory()->withMfa()->create();
        $this->expectException(AuthorizationException::class);

        app(PlatformRoleService::class)->configure($target->id, PlatformRoleType::Superadmin, true, $org->owner_user_id,
            'Tentative escalade propriétaire', (string) Str::uuid());
    }

    public function test_support_role_cannot_promote_a_platform_account(): void
    {
        $this->bootstrap();
        $support = PlatformRole::factory()->create();
        $this->expectException(AuthorizationException::class);

        app(PlatformRoleService::class)->configure($support->user_id, PlatformRoleType::Superadmin, true, $support->user_id,
            'Tentative auto promotion support', (string) Str::uuid());
    }

    /** @return array<string, array{string}> */
    public static function invalidAccounts(): array
    {
        return ['MFA absent' => ['mfa'], 'email unverified' => ['email']];
    }

    #[DataProvider('invalidAccounts')]
    public function test_bootstrap_requires_a_verified_account_with_confirmed_mfa(string $missing): void
    {
        $user = $missing === 'mfa' ? User::factory()->create() : User::factory()->withMfa()->unverified()->create();
        $this->expectException(DomainConflictException::class);

        app(PlatformRoleService::class)->configure($user->id, PlatformRoleType::Superadmin, true, null,
            'Initialisation opérateur approuvée', (string) Str::uuid(), true);
    }

    public function test_operator_audit_cannot_be_modified_through_the_query_builder(): void
    {
        $this->bootstrap();
        $this->expectException(QueryException::class);

        DB::table('platform_role_events')->update(['reason' => 'Effacer la trace']);
    }

    public function test_rollback_preserves_existing_platform_audit(): void
    {
        $this->bootstrap();
        $migration = require database_path('migrations/2026_10_11_183828_create_platform_roles_and_support_accesses.php');
        $this->expectException(\RuntimeException::class);

        $migration->down();
    }

    private function bootstrap(): User
    {
        $user = User::factory()->withMfa()->create();
        app(PlatformRoleService::class)->configure($user->id, PlatformRoleType::Superadmin, true, null,
            'Initialisation opérateur approuvée', (string) Str::uuid(), true);

        return $user;
    }

    public function test_role_changes_cannot_keep_the_old_version_through_the_database(): void
    {
        $role = PlatformRole::factory()->create();
        $this->expectException(QueryException::class);

        DB::table('platform_roles')->where('id', $role->id)->update(['active' => false]);
    }

    public function test_additive_migration_rolls_back_when_unused_and_recreates_its_guards(): void
    {
        User::factory()->create();
        $migration = require database_path('migrations/2026_10_11_183828_create_platform_roles_and_support_accesses.php');

        $migration->down();
        $migration->up();

        $this->assertDatabaseCount('platform_roles', 0);
        $this->assertDatabaseCount('platform_role_events', 0);
        $this->assertDatabaseCount('organization_support_accesses', 0);
    }
}
