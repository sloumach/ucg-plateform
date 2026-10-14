<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GameCatalogueSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalogue_seed_and_tenant_schema_are_registered_without_business_rows(): void
    {
        $this->assertDatabaseCount('game_catalogue_entries', 18);
        $this->assertDatabaseCount('organization_games', 0);
        $this->assertDatabaseCount('people', 0);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    /** @return array<string, array{string}> */
    public static function identityFields(): array
    {
        return ['id' => ['id'], 'key' => ['key'], 'kind' => ['kind'], 'game' => ['game_id'], 'scope' => ['scope_key'], 'defaults' => ['default_enabled']];
    }

    #[DataProvider('identityFields')]
    public function test_sql_cannot_change_global_identity(string $field): void
    {
        $game = GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail();
        $value = match ($field) {
            'id', 'game_id' => (string) Str::uuid(), 'kind' => 'map', 'default_enabled' => false, default => 'changed',
        };
        $this->reject(fn () => DB::table('game_catalogue_entries')->where('id', $game->id)->update([$field => $value]), '23514');
    }

    public function test_sql_rejects_wrong_parent_duplicate_keys_and_physical_deletion(): void
    {
        $platform = GameCatalogueEntry::query()->where('kind', 'platform')->firstOrFail();
        $this->reject(fn () => GameCatalogueEntry::factory()->create(['kind' => 'map', 'game_id' => $platform->id, 'scope_key' => $platform->id, 'definition' => null]), '23514');
        $game = GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail();
        $this->reject(fn () => GameCatalogueEntry::factory()->create(['key' => $game->key]), '23505');
        $this->reject(fn () => DB::table('game_catalogue_entries')->where('id', $game->id)->delete(), '23514');
    }

    public function test_sql_protects_tenant_identity_and_game_classification(): void
    {
        $setting = OrganizationGame::factory()->create();
        $other = Organization::factory()->create();
        $this->reject(fn () => DB::table('organization_games')->where('id', $setting->id)->update(['organization_id' => $other->id]), '23514');
        $this->reject(fn () => DB::table('organization_games')->where('id', $setting->id)->delete(), '23514');
        $platform = GameCatalogueEntry::query()->where('kind', 'platform')->firstOrFail();
        $this->reject(fn () => OrganizationGame::factory()->create(['game_id' => $platform->id]), '23514');
        $this->assertDatabaseCount('organization_games', 1);
    }

    public function test_organization_exports_contain_only_their_settings_and_no_global_catalogue(): void
    {
        $setting = OrganizationGame::factory()->create();
        $other = OrganizationGame::factory()->create();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($setting->organization_id, 1000));
        $settings = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'organization_games'));
        $this->assertCount(1, $settings);
        $this->assertSame($setting->id, $settings[0]['data']['id']);
        $this->assertNotSame($other->id, $settings[0]['data']['id']);
        $this->assertNotContains('game_catalogue_entries', array_column($rows, 'table'));
        $this->assertNotContains('game_catalogue_events', array_column($rows, 'table'));
    }

    public function test_rollback_refuses_any_tenant_settings(): void
    {
        OrganizationGame::factory()->create();
        $migration = require database_path('migrations/2026_10_13_120201_create_game_catalogue_and_organization_games.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain tenant data.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot discard', $error->getMessage());
            $this->assertDatabaseCount('organization_games', 1);
        }
    }

    public function test_rollback_refuses_a_changed_global_revision(): void
    {
        DB::table('game_catalogue_state')->update(['version' => (string) Str::uuid()]);
        $migration = require database_path('migrations/2026_10_13_120201_create_game_catalogue_and_organization_games.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_sql_changes_refresh_the_global_revision_and_prevent_lossy_rollback(): void
    {
        $before = DB::table('game_catalogue_state')->value('version');
        GameCatalogueEntry::factory()->create();
        $after = DB::table('game_catalogue_state')->value('version');
        $this->assertNotSame($before, $after);
        $this->assertTrue(Str::isUuid($after));
        $migration = require database_path('migrations/2026_10_13_120201_create_game_catalogue_and_organization_games.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_empty_migration_rolls_back_and_recreates_the_complete_catalogue(): void
    {
        $this->assertDatabaseCount('game_catalogue_entries', 18);
        $migration = require database_path('migrations/2026_10_13_120201_create_game_catalogue_and_organization_games.php');
        $teams = require database_path('migrations/2026_10_13_143631_create_teams_table.php');
        $memberships = require database_path('migrations/2026_10_13_152226_create_team_membership_and_movement_tables.php');
        $lineups = require database_path('migrations/2026_10_13_204834_create_team_lineup_tables.php');
        $eligibility = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        $opponents = require database_path('migrations/2026_10_14_121838_create_external_opponents_and_snapshots.php');
        $opponents->down();
        $eligibility->down();
        $lineups->down();
        $memberships->down();
        $teams->down();
        $migration->down();
        $migration->up();
        $teams->up();
        $memberships->up();
        $lineups->up();
        $eligibility->up();
        $opponents->up();
        $this->assertDatabaseCount('game_catalogue_entries', 18);
        $this->assertDatabaseCount('organization_games', 0);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_global_audit_is_append_only_and_baseline_is_immutable(): void
    {
        $actor = User::factory()->create();
        $game = GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail();
        DB::table('game_catalogue_events')->insert(['id' => (string) Str::uuid(), 'entry_id' => $game->id, 'actor_user_id' => $actor->id,
            'request_id' => (string) Str::uuid(), 'catalogue_version' => DB::table('game_catalogue_state')->value('version'),
            'before' => '[]', 'after' => '[]', 'created_at' => now()]);
        $this->reject(fn () => DB::table('game_catalogue_events')->delete(), '23514');
        $this->reject(fn () => DB::table('game_catalogue_events')->update(['after' => '[]']), '23514');
        $this->reject(fn () => DB::table('game_catalogue_state')->update(['initial_revision' => (string) Str::uuid()]), '23514');
        $initial = DB::table('game_catalogue_state')->value('initial_revision');
        DB::table('game_catalogue_state')->update(['version' => (string) Str::uuid()]);
        $this->reject(fn () => DB::table('game_catalogue_state')->update(['version' => $initial]), '23514');
        $this->assertDatabaseCount('game_catalogue_events', 1);
    }

    public function test_migration_imports_valid_legacy_overrides_and_rejects_invalid_config_before_ddl(): void
    {
        $this->assertDatabaseCount('game_catalogue_entries', 18);
        $org = Organization::factory()->create();
        $migration = require database_path('migrations/2026_10_13_120201_create_game_catalogue_and_organization_games.php');
        $teams = require database_path('migrations/2026_10_13_143631_create_teams_table.php');
        $memberships = require database_path('migrations/2026_10_13_152226_create_team_membership_and_movement_tables.php');
        $lineups = require database_path('migrations/2026_10_13_204834_create_team_lineup_tables.php');
        $eligibility = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        $opponents = require database_path('migrations/2026_10_14_121838_create_external_opponents_and_snapshots.php');
        $opponents->down();
        $eligibility->down();
        $lineups->down();
        $memberships->down();
        $teams->down();
        $migration->down();
        config(['game_profiles.tenant_enabled_games' => [$org->id => ['valorant']], 'game_profiles.games.valorant.platforms' => ['unknown' => []]]);
        try {
            $migration->up();
            $this->fail('Invalid configuration must be rejected before DDL.');
        } catch (RuntimeException) {
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('game_catalogue_entries'));
        }
        config(['game_profiles.games.valorant.platforms' => ['pc' => ['riot'], 'playstation' => ['riot'], 'xbox' => ['riot']]]);
        $migration->up();
        $teams->up();
        $memberships->up();
        $lineups->up();
        $eligibility->up();
        $opponents->up();
        $this->assertDatabaseCount('organization_games', 4);
        $this->assertSame(1, OrganizationGame::query()->where('organization_id', $org->id)->where('enabled', true)->count());
    }

    public function test_model_cannot_delete_or_rewrite_catalogue_keys(): void
    {
        $entry = GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail();
        $this->expectException(DomainConflictException::class);
        $entry->delete();
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write, string $postgresCode): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must reject this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? $postgresCode : '23000', (string) $error->getCode());
        }
    }
}
