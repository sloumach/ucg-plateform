<?php

namespace Tests\Feature;

use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalid(): array
    {
        return ['identity' => [['id' => 'a92d5485-12f3-4b3a-8d0b-c404b8ac9001']], 'unknown status' => [['status' => 'fake']],
            'recorded live' => [['status' => 'live']], 'share without authorization' => [['visibility' => 'public']],
            'private release' => [['publish_at' => '2026-10-20 12:00:00']]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalid')]
    public function test_database_rejects_invalid_states(array $changes): void
    {
        $item = MediaItem::factory()->create();
        try {
            DB::transaction(fn () => DB::table('media_items')->where('id', $item->id)->update($changes));
            $this->fail('Invalid media must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseHas('media_items', ['id' => $item->id, 'visibility' => 'private', 'status' => 'ended']);
        }
    }

    public function test_team_reference_must_belong_to_same_tenant(): void
    {
        $item = MediaItem::factory()->create();
        $foreign = Team::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('media_items')->where('id', $item->id)->update(['team_id' => $foreign->id]));
    }

    public function test_scrim_sharing_requires_release_even_with_authorization(): void
    {
        $item = MediaItem::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('media_items')->where('id', $item->id)->update([
            'is_scrim' => true, 'visibility' => 'public', 'sharing_authorized_by' => $item->getAttribute('created_by'), 'sharing_authorized_at' => now()]));
    }

    public function test_archive_cannot_be_reopened_or_deleted_in_bulk(): void
    {
        $item = MediaItem::factory()->create(['archived_at' => now()]);
        foreach ([fn () => DB::table('media_items')->where('id', $item->id)->update(['archived_at' => null, 'version' => (string) Str::uuid()]),
            fn () => DB::table('media_items')->where('id', $item->id)->delete()] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Archived media is immutable.');
            } catch (QueryException) {
                $this->assertModelExists($item);
            }
        }
    }

    public function test_empty_migration_round_trip_restores_constraints(): void
    {
        $migration = require database_path('migrations/2026_10_16_192806_create_media_items_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('media_items'));
        $migration->up();
        $this->test_scrim_sharing_requires_release_even_with_authorization();
    }

    public function test_rollback_refuses_existing_media(): void
    {
        MediaItem::factory()->create();
        $migration = require database_path('migrations/2026_10_16_192806_create_media_items_table.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain data.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('media_items', 1);
        }
    }
}
