<?php

namespace Tests\Feature;

use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\AnnouncementComment;
use App\Modules\Media\Domain\Models\CommunicationEvent;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommunicationSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalid(): array
    {
        return ['identity' => [['id' => 'a92d5485-12f3-4b3a-8d0b-c404b8ac9001']],
            'target without reference' => [['target_type' => 'team']], 'draft with publication' => [['published_at' => '2026-10-16 12:00:00']],
            'invalid status' => [['status' => 'bad']], 'publication without publisher' => [['status' => 'published', 'published_at' => '2026-10-16 12:00:00']]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalid')]
    public function test_invalid_shapes_and_identity_updates_are_rejected(array $changes): void
    {
        $record = Announcement::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('announcements')->where('id', $record->id)->update($changes));
    }

    public function test_foreign_team_and_role_references_are_rejected_by_database(): void
    {
        foreach (['team' => Team::factory()->create()->id, 'role' => OrganizationRole::factory()->create()->id] as $type => $id) {
            $record = Announcement::factory()->create();
            try {
                DB::transaction(fn () => DB::table('announcements')->where('id', $record->id)->update(['target_type' => $type, $type.'_id' => $id]));
                $this->fail('Cross tenant reference must fail.');
            } catch (QueryException) {
                $this->assertDatabaseHas('announcements', ['id' => $record->id, 'target_type' => 'organization']);
            }
        }
    }

    public function test_published_content_is_frozen_and_withdrawal_is_terminal(): void
    {
        $record = Announcement::factory()->published()->create();
        foreach (['title' => 'Changed', 'status' => 'draft', 'expires_at' => now()->addDay()] as $field => $value) {
            try {
                DB::transaction(fn () => DB::table('announcements')->where('id', $record->id)->update([$field => $value]));
                $this->fail('Published content cannot be rewritten.');
            } catch (QueryException) {
                $this->assertModelExists($record);
            }
        }
        DB::table('announcements')->where('id', $record->id)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('announcements')->where('id', $record->id)->update(['comments_enabled' => false]));
    }

    public function test_comment_history_is_append_only_and_parent_tenant_is_enforced(): void
    {
        $comment = AnnouncementComment::factory()->create();
        $foreign = Announcement::factory()->published()->create();
        foreach ([fn () => DB::table('announcement_comments')->where('id', $comment->id)->update(['body' => 'Changed']),
            fn () => DB::table('announcement_comments')->where('id', $comment->id)->delete(),
            fn () => AnnouncementComment::factory()->create(['organization_id' => $comment->organization_id, 'announcement_id' => $foreign->id])] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Invalid comment must fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('announcement_comments', 1);
            }
        }
    }

    public function test_notification_comment_must_belong_to_same_announcement_and_tenant(): void
    {
        $comment = AnnouncementComment::factory()->create();
        $other = Announcement::factory()->published()->create(['organization_id' => $comment->organization_id]);
        $event = new CommunicationEvent;
        $event->forceFill(['organization_id' => $comment->organization_id, 'announcement_id' => $other->id, 'comment_id' => $comment->id,
            'kind' => 'comment', 'actor_user_id' => $comment->created_by]);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $event->save());
    }

    public function test_empty_migration_round_trip_and_rollback_data_guard(): void
    {
        $migration = require database_path('migrations/2026_10_16_210818_create_communication_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('announcements'));
        $migration->up();
        Announcement::factory()->create();
        try {
            $migration->down();
            $this->fail('Communication history must be retained.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('announcements', 1);
            $this->assertTrue(Schema::hasTable('communication_receipts'));
        }
    }
}
