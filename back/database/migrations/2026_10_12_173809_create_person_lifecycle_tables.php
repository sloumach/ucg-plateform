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
            throw new RuntimeException('HUM-004 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('person_periods', function (Blueprint $table): void {
            $this->identity($table);
            $table->enum('kind', ['assignment', 'unavailability']);
            $table->string('role_key', 32)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->enum('status', ['active', 'closed', 'cancelled'])->default('active');
            $table->uuid('version');
            $table->timestamps();
            $table->index(['organization_id', 'person_id', 'kind', 'status', 'starts_at'], 'periods_person_kind_time');
        });
        Schema::create('person_departure_items', function (Blueprint $table): void {
            $this->identity($table);
            $table->enum('kind', ['equipment', 'obligation']);
            $table->text('title');
            $table->text('notes')->nullable();
            $table->enum('status', ['open', 'resolved', 'waived'])->default('open');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('version');
            $table->timestamps();
            $table->index(['organization_id', 'person_id', 'status', 'id'], 'departure_items_person_status');
        });
        Schema::create('person_departures', function (Blueprint $table): void {
            $this->identity($table);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('reason_key', ['resignation', 'end_of_contract', 'decision', 'other']);
            $table->timestampTz('departed_at');
            $table->uuid('previous_version');
            $table->uuid('person_version');
            $table->unsignedInteger('pending_items_count');
            $table->timestamps();
            $table->unique(['organization_id', 'person_id'], 'departure_once_per_person');
        });
        $this->guards();
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_people_departure_frozen() RETURNS trigger AS $$ BEGIN
                IF EXISTS (SELECT 1 FROM person_departures WHERE organization_id=OLD.organization_id AND person_id=OLD.id)
                    AND (NEW.status != 'former' OR NEW.user_id IS DISTINCT FROM OLD.user_id) THEN
                    RAISE EXCEPTION 'Departed person identity is frozen' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER people_departure_frozen BEFORE UPDATE ON people FOR EACH ROW EXECUTE FUNCTION ucg_people_departure_frozen()");
        } else {
            DB::unprepared("CREATE TRIGGER people_departure_frozen BEFORE UPDATE ON people
                WHEN EXISTS (SELECT 1 FROM person_departures WHERE organization_id=OLD.organization_id AND person_id=OLD.id)
                AND (NEW.status != 'former' OR NEW.user_id IS NOT OLD.user_id)
                BEGIN SELECT RAISE(ABORT, 'Departed person identity is frozen'); END");
        }
    }

    private function identity(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
        $table->uuid('person_id');
        $table->foreign(['organization_id', 'person_id'])->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
        $table->unique(['organization_id', 'id']);
        $table->index(['organization_id', 'person_id', 'id']);
    }

    private function guards(): void
    {
        $periodInvalid = "(NEW.ends_at IS NOT NULL AND NEW.ends_at <= NEW.starts_at)
            OR (NEW.kind = 'assignment' AND (NEW.role_key IS NULL OR NEW.role_key NOT IN ('player','substitute','captain','coach','analyst','staff')))
            OR (NEW.kind = 'unavailability' AND NEW.role_key IS NOT NULL)
            OR (NEW.status = 'closed' AND NEW.ends_at IS NULL)";
        $itemInvalid = "(NEW.status = 'open' AND NEW.completed_at IS NOT NULL) OR (NEW.status != 'open' AND NEW.completed_at IS NULL)";
        foreach (['person_periods', 'person_departure_items', 'person_departures'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                $extra = $table === 'person_periods' ? ' OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.role_key IS DISTINCT FROM OLD.role_key' : ($table === 'person_departure_items' ? ' OR NEW.kind IS DISTINCT FROM OLD.kind' : ' OR TRUE');
                DB::unprepared("CREATE FUNCTION ucg_{$table}_identity() RETURNS trigger AS $$ BEGIN
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.person_id IS DISTINCT FROM OLD.person_id{$extra} THEN
                    RAISE EXCEPTION 'Lifecycle identity is immutable' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_identity BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_identity();
                    CREATE FUNCTION ucg_{$table}_delete() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Lifecycle history must be retained' USING ERRCODE='23514'; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_delete()");
            } else {
                $extra = $table === 'person_periods' ? ' OR NEW.kind IS NOT OLD.kind OR NEW.role_key IS NOT OLD.role_key' : ($table === 'person_departure_items' ? ' OR NEW.kind IS NOT OLD.kind' : ' OR 1');
                DB::unprepared("CREATE TRIGGER {$table}_identity BEFORE UPDATE ON {$table} WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.person_id IS NOT OLD.person_id{$extra}
                    BEGIN SELECT RAISE(ABORT, 'Lifecycle identity is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Lifecycle history must be retained'); END");
            }
        }
        foreach (['person_periods' => $periodInvalid, 'person_departure_items' => $itemInvalid] as $table => $invalid) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_values() RETURNS trigger AS $$ BEGIN IF {$invalid} THEN RAISE EXCEPTION 'Invalid lifecycle values' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_values BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_values()");
            } else {
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_values_{$operation} BEFORE {$operation} ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid lifecycle values'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['person_departures', 'person_departure_items', 'person_periods'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('HUM-004 rollback cannot discard lifecycle history. Use a forward fix.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::unprepared('DROP TRIGGER IF EXISTS people_departure_frozen ON people; DROP FUNCTION IF EXISTS ucg_people_departure_frozen()');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS people_departure_frozen');
        }
        foreach (['person_departures', 'person_departure_items', 'person_periods'] as $table) {
            Schema::dropIfExists($table);
            if (DB::getDriverName() === 'pgsql') {
                foreach (['identity', 'delete', 'values'] as $guard) {
                    DB::unprepared("DROP FUNCTION IF EXISTS ucg_{$table}_{$guard}()");
                }
            }
        }
    }
};
