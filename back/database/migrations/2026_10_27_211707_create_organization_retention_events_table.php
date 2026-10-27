<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException('Retention controls require PostgreSQL or SQLite.');
        }
        Schema::create('organization_retention_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->enum('action', ['hold', 'release-hold', 'restrict-archive', 'release-archive', 'quarantine', 'release-quarantine']);
            $table->string('reference', 120);
            $table->json('state');
            $table->json('evidence')->nullable();
            $table->string('reason', 1000);
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->unique(['organization_id', 'version'], 'retention_event_version_unique');
            $table->unique(['organization_id', 'request_id'], 'retention_event_request_unique');
            $table->index('actor_user_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE organization_retention_events ADD CONSTRAINT retention_version_positive CHECK (version > 0);
                CREATE FUNCTION ucg_retention_event_guard() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Retention audit is immutable' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER retention_event_guard BEFORE UPDATE OR DELETE ON organization_retention_events
                    FOR EACH ROW EXECUTE FUNCTION ucg_retention_event_guard();
                SQL);
        } else {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER retention_event_guard_{$operation} BEFORE {$operation} ON organization_retention_events BEGIN SELECT RAISE(ABORT, 'Retention audit is immutable'); END;");
            }
            DB::unprepared("CREATE TRIGGER retention_event_version_guard BEFORE INSERT ON organization_retention_events WHEN NEW.version < 1 BEGIN SELECT RAISE(ABORT, 'Invalid retention version'); END;");
        }
    }

    public function down(): void
    {
        // A rollback must not silently erase preservation decisions or their audit.
        if (Schema::hasTable('organization_retention_events') && DB::table('organization_retention_events')->exists()) {
            throw new LogicException('Preserve retention events before rolling back this migration.');
        }
        Schema::dropIfExists('organization_retention_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_retention_event_guard()');
        }
    }
};
