<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Communication constraints require SQLite or PostgreSQL.');
        }
        Schema::create('announcements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->text('title');
            $t->text('body');
            $t->enum('target_type', ['organization', 'team', 'game', 'role']);
            $t->uuid('team_id')->nullable();
            $t->uuid('game_id')->nullable();
            $t->uuid('role_id')->nullable();
            $t->foreign(['organization_id', 'team_id'], 'announcement_team_tenant')->references(['organization_id', 'id'])->on('teams')->restrictOnDelete();
            $t->foreign('game_id')->references('id')->on('game_catalogue_entries')->restrictOnDelete();
            $t->foreign(['organization_id', 'role_id'], 'announcement_role_tenant')->references(['organization_id', 'id'])->on('organization_roles')->restrictOnDelete();
            $t->enum('status', ['draft', 'published', 'withdrawn'])->default('draft');
            $t->boolean('comments_enabled')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('published_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('withdrawn_at')->nullable();
            $t->uuid('version');
            $t->timestamps();
            $t->unique(['organization_id', 'id'], 'announcement_tenant_id');
            $t->index(['organization_id', 'status', 'published_at', 'id'], 'announcement_feed');
            foreach (['team', 'game', 'role'] as $target) {
                $t->index(['organization_id', $target.'_id', 'status'], 'announcement_'.$target.'_audience');
            }
        });
        Schema::create('announcement_comments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('announcement_id');
            $t->foreign(['organization_id', 'announcement_id'], 'comment_announcement_tenant')->references(['organization_id', 'id'])->on('announcements')->restrictOnDelete();
            $t->text('body');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'announcement_id', 'id'], 'comment_tenant_parent_id');
            $t->index(['organization_id', 'announcement_id', 'created_at', 'id'], 'comment_page');
            $t->index(['organization_id', 'announcement_id', 'created_by'], 'comment_participant');
        });
        Schema::create('communication_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('announcement_id');
            $t->uuid('comment_id')->nullable();
            $t->foreign(['organization_id', 'announcement_id'], 'communication_event_parent')->references(['organization_id', 'id'])->on('announcements')->restrictOnDelete();
            $t->foreign(['organization_id', 'announcement_id', 'comment_id'], 'communication_event_comment')->references(['organization_id', 'announcement_id', 'id'])->on('announcement_comments')->restrictOnDelete();
            $t->enum('kind', ['announcement', 'comment']);
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'id'], 'communication_event_tenant_id');
            $t->unique(['organization_id', 'comment_id'], 'communication_comment_once');
            $t->index(['organization_id', 'created_at', 'id'], 'communication_inbox');
        });
        Schema::create('communication_preferences', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->boolean('announcements_enabled')->default(true);
            $t->boolean('comments_enabled')->default(false);
            $t->uuid('version');
            $t->timestamps();
            $t->unique(['organization_id', 'user_id'], 'communication_preference_user');
        });
        Schema::create('communication_receipts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('event_id');
            $t->foreign(['organization_id', 'event_id'], 'communication_receipt_event')->references(['organization_id', 'id'])->on('communication_events')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('read_at');
            $t->unique(['organization_id', 'user_id', 'event_id'], 'communication_read_once');
        });
        DB::statement("CREATE UNIQUE INDEX communication_announcement_once ON communication_events (organization_id, announcement_id) WHERE kind = 'announcement'");
        $shape = "((NEW.target_type = 'organization' AND NEW.team_id IS NULL AND NEW.game_id IS NULL AND NEW.role_id IS NULL)
            OR (NEW.target_type = 'team' AND NEW.team_id IS NOT NULL AND NEW.game_id IS NULL AND NEW.role_id IS NULL)
            OR (NEW.target_type = 'game' AND NEW.game_id IS NOT NULL AND NEW.team_id IS NULL AND NEW.role_id IS NULL)
            OR (NEW.target_type = 'role' AND NEW.role_id IS NOT NULL AND NEW.team_id IS NULL AND NEW.game_id IS NULL))
            AND ((NEW.status = 'draft' AND NEW.published_at IS NULL AND NEW.published_by IS NULL AND NEW.withdrawn_at IS NULL)
              OR (NEW.status = 'published' AND NEW.published_at IS NOT NULL AND NEW.published_by IS NOT NULL AND NEW.withdrawn_at IS NULL)
              OR (NEW.status = 'withdrawn' AND NEW.published_at IS NOT NULL AND NEW.published_by IS NOT NULL AND NEW.withdrawn_at IS NOT NULL))
            AND (NEW.expires_at IS NULL OR NEW.published_at IS NULL OR NEW.expires_at > NEW.published_at)";
        $identity = ['id', 'organization_id', 'created_by', 'created_at'];
        $content = ['title', 'body', 'target_type', 'team_id', 'game_id', 'role_id', 'published_at', 'published_by', 'expires_at'];
        $eventShape = "(NEW.kind = 'announcement' AND NEW.comment_id IS NULL) OR (NEW.kind = 'comment' AND NEW.comment_id IS NOT NULL)";
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $op) {
                DB::unprepared('CREATE TRIGGER announcement_shape_'.strtolower($op)." BEFORE $op ON announcements
                    WHEN NOT ($shape) BEGIN SELECT RAISE(ABORT, 'Invalid announcement'); END");
            }
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $identity));
            $frozen = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $content));
            DB::unprepared("CREATE TRIGGER announcement_identity BEFORE UPDATE ON announcements
                WHEN ($immutable) OR OLD.status = 'withdrawn'
                  OR (OLD.status = 'published' AND (NEW.status NOT IN ('published','withdrawn') OR ($frozen)))
                BEGIN SELECT RAISE(ABORT, 'Immutable announcement'); END");
            DB::unprepared("CREATE TRIGGER announcement_no_delete BEFORE DELETE ON announcements BEGIN SELECT RAISE(ABORT, 'Withdraw announcement instead'); END");
            DB::unprepared("CREATE TRIGGER communication_event_shape BEFORE INSERT ON communication_events
                WHEN NOT ($eventShape) BEGIN SELECT RAISE(ABORT, 'Invalid notification event'); END");
            foreach (['announcement_comments', 'communication_events', 'communication_receipts'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $op) {
                    DB::unprepared("CREATE TRIGGER $table".'_'.strtolower($op)." BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'Append only communication history'); END");
                }
            }
        } else {
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $identity));
            $frozen = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $content));
            DB::unprepared("CREATE FUNCTION communication_announcement_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Withdraw announcement instead'; END IF;
                  IF TG_OP = 'UPDATE' AND (($immutable) OR OLD.status = 'withdrawn'
                    OR (OLD.status = 'published' AND (NEW.status NOT IN ('published','withdrawn') OR ($frozen))))
                    THEN RAISE EXCEPTION 'Immutable announcement'; END IF;
                  IF NOT ($shape) THEN RAISE EXCEPTION 'Invalid announcement'; END IF;
                  RETURN NEW;
                END; \$\$;
                CREATE TRIGGER announcement_guard BEFORE INSERT OR UPDATE OR DELETE ON announcements FOR EACH ROW EXECUTE FUNCTION communication_announcement_guard()");
            DB::unprepared("CREATE FUNCTION communication_append_only() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN RAISE EXCEPTION 'Append only communication history'; END; \$\$");
            foreach (['announcement_comments', 'communication_events', 'communication_receipts'] as $table) {
                DB::unprepared("CREATE TRIGGER $table".'_immutable'." BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION communication_append_only()");
            }
            DB::statement('ALTER TABLE communication_events ADD CONSTRAINT communication_event_shape CHECK ('.str_replace('NEW.', '', $eventShape).')');
        }
    }

    public function down(): void
    {
        $tables = ['communication_receipts', 'communication_preferences', 'communication_events', 'announcement_comments', 'announcements'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Communication records exist. Use a forward migration instead.');
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS communication_announcement_guard(); DROP FUNCTION IF EXISTS communication_append_only()');
        }
    }
};
