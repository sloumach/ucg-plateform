<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Scheduling\Domain\Models\CalendarFeed;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalendarFeedSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_identity_bounds_and_personal_uniqueness_are_enforced_in_the_database(): void
    {
        $feed = CalendarFeed::factory()->create();
        foreach ([['organization_id' => fake()->uuid()], ['user_id' => 0], ['before_days' => 31], ['after_days' => 0],
            ['before_days' => 30, 'after_days' => 90], ['token_hash' => null]] as $changes) {
            try {
                DB::transaction(fn () => DB::table('calendar_feeds')->where('id', $feed->id)->update($changes));
                $this->fail('Invalid feeds must be rejected.');
            } catch (QueryException) {
                $this->assertModelExists($feed);
            }
        }
        $this->expectException(QueryException::class);
        DB::transaction(fn () => CalendarFeed::factory()->create(['organization_id' => $feed->organization_id, 'user_id' => $feed->user_id]));
    }

    public function test_round_trip_portability_inventory_and_protective_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_17_164002_create_calendar_feeds_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('calendar_feeds'));
        $migration->up();
        $feed = CalendarFeed::factory()->create();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $repo = app(PortabilityRepository::class);
        $repo->assertCatalog();
        $rows = iterator_to_array($repo->rows($feed->organization_id, 100));
        $feeds = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'calendar_feeds'));
        $this->assertCount(1, $feeds);
        $this->assertSame($feed->token_hash, $feeds[0]['data']['token_hash']);
        $this->assertArrayNotHasKey('token', $feeds[0]['data']);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
