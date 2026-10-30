<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Operations alerts require SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('operations_alerts', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->char('scope', 64);
            $table->string('component', 64);
            $table->string('code', 64);
            $table->enum('severity', ['warning', 'error']);
            $table->enum('status', ['open', 'acknowledged', 'resolved']);
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('observations');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->unique(['scope', 'component']);
            $table->index(['scope', 'last_seen_at', 'id']);
        });
        Schema::create('operations_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->char('alert_id', 64);
            $table->foreign('alert_id')->references('id')->on('operations_alerts')->restrictOnDelete();
            $table->enum('action', ['opened', 'changed', 'acknowledged', 'resolved', 'reopened', 'escalated']);
            $table->unsignedInteger('version');
            $table->uuid('incident_id')->nullable();
            $table->timestampTz('created_at');
            $table->index(['alert_id', 'id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared('CREATE TRIGGER ops_alert_events_no_'.strtolower($action).' BEFORE '.$action.
                    " ON operations_alert_events BEGIN SELECT RAISE(ABORT, 'Operations alert history is immutable'); END");
            }
        } else {
            DB::unprepared("CREATE FUNCTION ops_alert_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Operations alert history is immutable'; END; $$;
                CREATE TRIGGER ops_alert_events_guard BEFORE UPDATE OR DELETE ON operations_alert_events FOR EACH ROW EXECUTE FUNCTION ops_alert_events_immutable()");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('operations_alert_events')->exists() || DB::table('operations_alerts')->exists()) {
            throw new RuntimeException('Preserve operations alert history; use a reviewed forward migration.');
        }
        Schema::dropIfExists('operations_alert_events');
        Schema::dropIfExists('operations_alerts');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ops_alert_events_immutable()');
        }
    }
};
