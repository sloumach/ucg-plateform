<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TeamSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_schema_and_portability_inventory_include_tenant_owned_teams(): void
    {
        $this->assertDatabaseCount('teams', 0);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    /** @return array<string, array{string}> */
    public static function immutableFields(): array
    {
        return ['id' => ['id'], 'tenant' => ['organization_id'], 'game' => ['game_id'], 'slug' => ['slug'], 'season' => ['season']];
    }

    #[DataProvider('immutableFields')]
    public function test_bulk_sql_cannot_move_or_repurpose_a_team(string $field): void
    {
        $team = Team::factory()->create();
        $value = in_array($field, ['id', 'organization_id', 'game_id'], true) ? (string) Str::uuid() : 'changed';
        $this->reject(fn () => DB::table('teams')->where('id', $team->id)->update([$field => $value]), '23514');
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'season' => '2026']);
    }

    public function test_sql_rejects_non_game_reference_and_incoherent_custom_category(): void
    {
        $platform = GameCatalogueEntry::query()->where('kind', 'platform')->firstOrFail();
        $this->reject(fn () => Team::factory()->create(['game_id' => $platform->id]), '23514');
        $this->reject(fn () => Team::factory()->create(['kind' => 'custom', 'custom_category' => null]), '23514');
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_sql_cannot_delete_or_change_an_archived_team(): void
    {
        $team = Team::factory()->create(['status' => 'archived']);
        $this->reject(fn () => DB::table('teams')->where('id', $team->id)->delete(), '23514');
        $this->reject(fn () => DB::table('teams')->where('id', $team->id)->update(['name' => 'Changed']), '23514');
        $this->reject(fn () => DB::table('teams')->where('id', $team->id)->update(['status' => 'active']), '23514');
        $this->assertDatabaseCount('teams', 1);
    }

    public function test_slug_uniqueness_covers_archives_within_the_tenant(): void
    {
        $team = Team::factory()->create(['status' => 'archived']);
        $this->reject(fn () => Team::factory()->create(['organization_id' => $team->organization_id, 'slug' => $team->slug]), '23505');
        Team::factory()->create(['slug' => $team->slug]);
        $this->assertDatabaseCount('teams', 2);
    }

    public function test_export_contains_only_the_target_tenant_teams(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($team->organization_id, 1000));
        $teams = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'teams'));
        $this->assertCount(1, $teams);
        $this->assertSame($team->id, $teams[0]['data']['id']);
        $this->assertNotSame($other->id, $teams[0]['data']['id']);
    }

    public function test_unused_migration_round_trip_recreates_guards(): void
    {
        $this->assertDatabaseCount('teams', 0);
        $migration = require database_path('migrations/2026_10_13_143631_create_teams_table.php');
        $memberships = require database_path('migrations/2026_10_13_152226_create_team_membership_and_movement_tables.php');
        $lineups = require database_path('migrations/2026_10_13_204834_create_team_lineup_tables.php');
        $eligibility = require database_path('migrations/2026_10_13_215251_create_team_eligibility_rules_and_reports.php');
        $agreements = require database_path('migrations/2026_10_16_000918_create_sponsorship_agreements_and_exposures.php');
        $campaigns = require database_path('migrations/2026_10_16_013526_create_sponsorship_campaigns_and_deliverables.php');
        $media = require database_path('migrations/2026_10_16_192806_create_media_items_table.php');
        $communication = require database_path('migrations/2026_10_16_210818_create_communication_tables.php');
        $communication->down();
        $media->down();
        $campaigns->down();
        $agreements->down();
        $eligibility->down();
        $lineups->down();
        $memberships->down();
        $migration->down();
        $migration->up();
        $memberships->up();
        $lineups->up();
        $eligibility->up();
        $agreements->up();
        $campaigns->up();
        $media->up();
        $communication->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_rollback_never_discards_team_history(): void
    {
        Team::factory()->create(['status' => 'archived']);
        $migration = require database_path('migrations/2026_10_13_143631_create_teams_table.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write, string $code): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL must reject this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? $code : '23000', (string) $error->getCode());
        }
    }
}
