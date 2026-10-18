<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Domain\Models\ExternalOpponentSnapshot;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
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

class ExternalOpponentSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_schema_and_portability_catalog_include_both_tenant_tables(): void
    {
        $this->assertDatabaseCount('external_opponents', 0);
        $this->assertDatabaseCount('external_opponent_snapshots', 0);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    /** @return array<string, array{string}> */
    public static function identityFields(): array
    {
        return ['id' => ['id'], 'tenant' => ['organization_id'], 'key' => ['key'], 'kind' => ['kind'], 'game' => ['game_id']];
    }

    #[DataProvider('identityFields')]
    public function test_bulk_sql_cannot_move_or_repurpose_references(string $field): void
    {
        $opponent = ExternalOpponent::factory()->create();
        $value = match ($field) {
            'kind' => 'organization', 'key' => 'changed', default => (string) Str::uuid(),
        };
        $this->reject(fn () => DB::table('external_opponents')->where('id', $opponent->id)->update([$field => $value]));
        $this->assertDatabaseHas('external_opponents', ['id' => $opponent->id, 'name' => 'Adversaire public']);
    }

    public function test_game_references_must_point_to_a_game_and_archives_cannot_be_changed_or_deleted(): void
    {
        $platform = GameCatalogueEntry::query()->where('kind', 'platform')->firstOrFail();
        $this->reject(fn () => ExternalOpponent::factory()->create(['game_id' => $platform->id]));
        $opponent = ExternalOpponent::factory()->create(['status' => 'archived']);
        $this->reject(fn () => DB::table('external_opponents')->where('id', $opponent->id)->update(['name' => 'Overwrite']));
        $this->reject(fn () => DB::table('external_opponents')->where('id', $opponent->id)->delete());
        $this->assertDatabaseCount('external_opponents', 1);
    }

    public function test_keys_remain_reserved_in_archives_and_can_repeat_in_another_tenant(): void
    {
        $opponent = ExternalOpponent::factory()->create(['status' => 'archived']);
        $this->reject(fn () => ExternalOpponent::factory()->create(['organization_id' => $opponent->organization_id, 'key' => $opponent->key]), '23505');
        ExternalOpponent::factory()->create(['key' => $opponent->key]);
        $this->assertDatabaseCount('external_opponents', 2);
    }

    public function test_sql_snapshot_must_match_public_source_and_tenant(): void
    {
        $opponent = ExternalOpponent::factory()->create();
        $attributes = $this->attributes($opponent);
        $foreign = Organization::factory()->create();
        $this->reject(fn () => DB::table('external_opponent_snapshots')->insert([...$attributes, 'organization_id' => $foreign->id]));
        $this->reject(fn () => DB::table('external_opponent_snapshots')->insert([...$attributes, 'name' => 'Forged']));
        $this->reject(fn () => DB::table('external_opponent_snapshots')->insert([...$attributes, 'opponent_version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('external_opponent_snapshots')->insert([...$attributes, 'game_name' => 'Forged']));
        DB::table('external_opponent_snapshots')->insert($attributes);
        $this->assertDatabaseCount('external_opponent_snapshots', 1);
    }

    public function test_archived_source_cannot_be_captured_and_snapshots_are_append_only(): void
    {
        $opponent = ExternalOpponent::factory()->create();
        $attributes = $this->attributes($opponent);
        DB::table('external_opponent_snapshots')->insert($attributes);
        $this->reject(fn () => DB::table('external_opponent_snapshots')->where('id', $attributes['id'])->update(['name' => 'Changed']));
        $this->reject(fn () => DB::table('external_opponent_snapshots')->where('id', $attributes['id'])->delete());
        $opponent->forceFill(['status' => 'archived'])->save();
        $this->reject(fn () => DB::table('external_opponent_snapshots')->insert([...$attributes, 'id' => (string) Str::uuid()]));
        $this->assertDatabaseHas('external_opponent_snapshots', ['id' => $attributes['id'], 'name' => 'Adversaire public']);
    }

    public function test_model_also_prevents_reference_deletion(): void
    {
        $opponent = ExternalOpponent::factory()->create();
        $this->expectException(DomainConflictException::class);
        $opponent->delete();
    }

    public function test_model_also_prevents_snapshot_rewriting(): void
    {
        $opponent = ExternalOpponent::factory()->create();
        $attributes = $this->attributes($opponent);
        DB::table('external_opponent_snapshots')->insert($attributes);
        $snapshot = ExternalOpponentSnapshot::query()->where('id', $attributes['id'])->firstOrFail();
        $this->expectException(DomainConflictException::class);
        $snapshot->forceFill(['name' => 'Overwrite'])->save();
    }

    public function test_export_contains_only_the_target_tenant_references_and_snapshots(): void
    {
        $opponent = ExternalOpponent::factory()->create();
        $foreign = ExternalOpponent::factory()->create();
        DB::table('external_opponent_snapshots')->insert($this->attributes($opponent));
        DB::table('external_opponent_snapshots')->insert($this->attributes($foreign));
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($opponent->organization_id, 1000));
        $references = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'external_opponents'));
        $snapshots = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'external_opponent_snapshots'));
        $this->assertCount(1, $references);
        $this->assertCount(1, $snapshots);
        $this->assertSame($opponent->id, $references[0]['data']['id']);
        $this->assertSame($opponent->id, $snapshots[0]['data']['opponent_id']);
    }

    public function test_empty_migration_round_trip_recreates_guards(): void
    {
        $this->assertDatabaseCount('external_opponents', 0);
        $migration = require database_path('migrations/2026_10_14_121838_create_external_opponents_and_snapshots.php');
        $matches = require database_path('migrations/2026_10_17_225945_create_matches_participants_and_status_events.php');
        $matchLineups = require database_path('migrations/2026_10_18_101019_create_match_lineup_revisions.php');
        $matchScores = require database_path('migrations/2026_10_18_135008_create_match_score_rules_and_game_instances.php');
        $matchScores->down();
        $matchLineups->down();
        $matches->down();
        $migration->down();
        $migration->up();
        $matches->up();
        $matchLineups->up();
        $matchScores->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $opponent = ExternalOpponent::factory()->create();
        $this->reject(fn () => DB::table('external_opponents')->where('id', $opponent->id)->delete());
    }

    public function test_rollback_refuses_to_discard_history_before_any_ddl(): void
    {
        ExternalOpponent::factory()->create();
        $migration = require database_path('migrations/2026_10_14_121838_create_external_opponents_and_snapshots.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse existing data.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot discard', $error->getMessage());
            $this->assertDatabaseCount('external_opponents', 1);
            $this->assertDatabaseCount('external_opponent_snapshots', 0);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(ExternalOpponent $opponent): array
    {
        return ['id' => (string) Str::uuid(), 'organization_id' => $opponent->organization_id, 'opponent_id' => $opponent->id,
            'opponent_version' => $opponent->version, 'context_label' => 'Match de test', 'kind' => $opponent->kind,
            'name' => $opponent->name, 'short_name' => $opponent->short_name, 'organization_name' => $opponent->organization_name,
            'game_key' => $opponent->game?->key, 'game_name' => $opponent->game?->name,
            'source_label' => $opponent->source_label, 'source_url' => $opponent->source_url,
            'observed_at' => $opponent->getRawOriginal('observed_at'), 'captured_at' => now(),
            'created_by' => User::factory()->create()->id];
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write, string $postgresCode = '23514'): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must reject this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? $postgresCode : '23000', (string) $error->getCode());
        }
    }
}
