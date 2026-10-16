<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->uuid('team_id');
            $t->foreign(['organization_id', 'team_id'], 'media_team_tenant')->references(['organization_id', 'id'])->on('teams')->restrictOnDelete();
            $t->string('title', 160);
            $t->enum('kind', ['stream', 'vod', 'clip']);
            $t->enum('provider', ['youtube', 'twitch']);
            $t->string('url', 500);
            $t->enum('status', ['scheduled', 'live', 'ended', 'unavailable']);
            $t->timestampTz('status_observed_at');
            $t->boolean('is_scrim')->default(false);
            $t->enum('visibility', ['private', 'organization', 'public'])->default('private');
            $t->timestampTz('publish_at')->nullable();
            $t->foreignId('sharing_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('sharing_authorized_at')->nullable();
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('version');
            $t->timestamps();
            $t->index(['organization_id', 'archived_at', 'created_at', 'id'], 'media_page');
            $t->index(['organization_id', 'team_id', 'created_at', 'id'], 'media_team_page');
            $t->index(['organization_id', 'visibility', 'publish_at'], 'media_visibility');
        });
        $valid = "(NEW.kind = 'stream' OR NEW.status IN ('ended','unavailable'))
            AND ((NEW.visibility = 'private' AND NEW.publish_at IS NULL AND NEW.sharing_authorized_by IS NULL AND NEW.sharing_authorized_at IS NULL)
              OR (NEW.visibility IN ('organization','public') AND NEW.sharing_authorized_by IS NOT NULL AND NEW.sharing_authorized_at IS NOT NULL
                AND (NOT NEW.is_scrim OR NEW.publish_at IS NOT NULL)))";
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER media_shape_'.strtolower($operation)." BEFORE $operation ON media_items
                    WHEN NOT ($valid) BEGIN SELECT RAISE(ABORT, 'Invalid media state'); END");
            }
            DB::unprepared("CREATE TRIGGER media_identity BEFORE UPDATE ON media_items
                WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.created_by IS NOT OLD.created_by OR OLD.archived_at IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Immutable media'); END");
            DB::unprepared("CREATE TRIGGER media_no_delete BEFORE DELETE ON media_items BEGIN SELECT RAISE(ABORT, 'Archive media instead'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION media_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Archive media instead'; END IF;
                  IF TG_OP = 'UPDATE' AND (NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by OR OLD.archived_at IS NOT NULL) THEN RAISE EXCEPTION 'Immutable media'; END IF;
                  IF NOT ($valid) THEN RAISE EXCEPTION 'Invalid media state'; END IF;
                  RETURN NEW;
                END; \$\$;
                CREATE TRIGGER media_guard BEFORE INSERT OR UPDATE OR DELETE ON media_items FOR EACH ROW EXECUTE FUNCTION media_guard()");
        } else {
            throw new RuntimeException('Media constraints require SQLite or PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_items') && DB::table('media_items')->exists()) {
            throw new RuntimeException('Media records exist. Use a forward migration instead of discarding them.');
        }
        Schema::dropIfExists('media_items');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS media_guard()');
        }
    }
};
