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
            throw new RuntimeException('Availability requires SQLite or PostgreSQL.');
        }
        Schema::create('availabilities', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('person_id');
            $t->foreign(['organization_id', 'person_id'], 'availability_person_tenant')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $t->enum('status', ['available', 'unavailable', 'uncertain']);
            $t->text('comment')->nullable();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('origin_timezone', 100);
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('version');
            $t->unsignedInteger('number');
            $t->timestamps();
            $t->unique(['organization_id', 'id', 'person_id'], 'availability_tenant_identity');
            $t->index(['organization_id', 'person_id', 'cancelled_at', 'starts_at', 'id'], 'availability_person_window');
            $t->index(['organization_id', 'cancelled_at', 'starts_at', 'id'], 'availability_collective_window');
        });
        Schema::create('availability_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('availability_id');
            $t->uuid('person_id');
            $t->foreign(['organization_id', 'availability_id', 'person_id'], 'availability_revision_parent')->references(['organization_id', 'id', 'person_id'])->on('availabilities')->restrictOnDelete();
            $t->enum('action', ['created', 'updated', 'cancelled']);
            $t->enum('status', ['available', 'unavailable', 'uncertain']);
            $t->text('comment')->nullable();
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('origin_timezone', 100);
            $t->timestampTz('cancelled_at')->nullable();
            $t->uuid('version');
            $t->unsignedInteger('number');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'availability_id', 'number'], 'availability_revision_number');
        });
        $sqlite = DB::getDriverName() === 'sqlite';
        $period = $sqlite ? 'julianday(NEW.starts_at) IS NOT NULL AND julianday(NEW.ends_at) IS NOT NULL AND julianday(NEW.ends_at)>julianday(NEW.starts_at) AND julianday(NEW.ends_at)-julianday(NEW.starts_at)<=31'
            : "NEW.ends_at>NEW.starts_at AND NEW.ends_at<=NEW.starts_at+INTERVAL '31 days'";
        $shape = "($period) AND NEW.number>0";
        if ($sqlite) {
            foreach (['INSERT', 'UPDATE'] as $op) {
                DB::unprepared('CREATE TRIGGER availability_shape_'.strtolower($op)." BEFORE $op ON availabilities WHEN NOT ($shape)
                    BEGIN SELECT RAISE(ABORT,'Invalid availability period'); END");
            }
            DB::unprepared("CREATE TRIGGER availability_identity BEFORE UPDATE ON availabilities
                WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.person_id IS NOT OLD.person_id
                  OR NEW.created_by IS NOT OLD.created_by OR NEW.created_at IS NOT OLD.created_at OR OLD.cancelled_at IS NOT NULL
                  OR NEW.number<>OLD.number+1 OR NEW.version=OLD.version
                BEGIN SELECT RAISE(ABORT,'Immutable availability identity or cancelled record'); END");
            DB::unprepared("CREATE TRIGGER availability_no_delete BEFORE DELETE ON availabilities BEGIN SELECT RAISE(ABORT,'Cancel availability instead'); END");
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared('CREATE TRIGGER availability_revision_no_'.strtolower($op)." BEFORE $op ON availability_revisions
                    BEGIN SELECT RAISE(ABORT,'Availability history is immutable'); END");
            }
        } else {
            DB::unprepared("CREATE FUNCTION availability_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Cancel availability instead'; END IF;
                    IF TG_OP='UPDATE' AND (NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.person_id IS DISTINCT FROM OLD.person_id OR NEW.created_by IS DISTINCT FROM OLD.created_by
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at OR OLD.cancelled_at IS NOT NULL
                        OR NEW.number<>OLD.number+1 OR NEW.version=OLD.version)
                        THEN RAISE EXCEPTION 'Immutable availability identity or cancelled record'; END IF;
                    IF NOT ($shape) THEN RAISE EXCEPTION 'Invalid availability period'; END IF;
                    RETURN NEW;
                END; \$\$;
                CREATE TRIGGER availability_guard BEFORE INSERT OR UPDATE OR DELETE ON availabilities FOR EACH ROW EXECUTE FUNCTION availability_guard()");
            DB::unprepared("CREATE FUNCTION availability_revision_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN RAISE EXCEPTION 'Availability history is immutable'; END; \$\$;
                CREATE TRIGGER availability_revision_immutable BEFORE UPDATE OR DELETE ON availability_revisions FOR EACH ROW EXECUTE FUNCTION availability_revision_immutable()");
        }
    }

    public function down(): void
    {
        foreach (['availability_revisions', 'availabilities'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Availability history exists. Use a forward migration.');
            }
        }
        Schema::dropIfExists('availability_revisions');
        Schema::dropIfExists('availabilities');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS availability_guard(); DROP FUNCTION IF EXISTS availability_revision_immutable()');
        }
    }
};
