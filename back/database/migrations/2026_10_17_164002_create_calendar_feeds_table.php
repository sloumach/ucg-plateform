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
            throw new RuntimeException('Calendar feeds require SQLite or PostgreSQL.');
        }
        Schema::create('calendar_feeds', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('token_hash', 64)->nullable();
            $t->unsignedSmallInteger('before_days');
            $t->unsignedSmallInteger('after_days');
            $t->timestampTz('expires_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->uuid('version');
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });
        $invalid = 'NEW.before_days<0 OR NEW.before_days>30 OR NEW.after_days<1 OR NEW.after_days>90 OR NEW.before_days+NEW.after_days>93 OR ((NEW.token_hash IS NULL) <> (NEW.revoked_at IS NOT NULL))';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $op) {
                DB::unprepared('CREATE TRIGGER calendar_feed_shape_'.strtolower($op)." BEFORE $op ON calendar_feeds WHEN $invalid BEGIN SELECT RAISE(ABORT,'Invalid calendar feed'); END");
            }
            DB::unprepared("CREATE TRIGGER calendar_feed_identity BEFORE UPDATE ON calendar_feeds WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.user_id IS NOT OLD.user_id OR NEW.created_at IS NOT OLD.created_at BEGIN SELECT RAISE(ABORT,'Immutable calendar feed owner'); END");
        } else {
            DB::unprepared("CREATE FUNCTION calendar_feed_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF $invalid THEN RAISE EXCEPTION 'Invalid calendar feed'; END IF;
                IF TG_OP='UPDATE' AND (NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at) THEN RAISE EXCEPTION 'Immutable calendar feed owner'; END IF;
                RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_feed_guard BEFORE INSERT OR UPDATE ON calendar_feeds FOR EACH ROW EXECUTE FUNCTION calendar_feed_guard()");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('calendar_feeds') && DB::table('calendar_feeds')->exists()) {
            throw new RuntimeException('Calendar feeds exist. Use a forward migration.');
        }
        Schema::dropIfExists('calendar_feeds');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS calendar_feed_guard()');
        }
    }
};
