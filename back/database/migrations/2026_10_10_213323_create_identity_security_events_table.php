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
        Schema::create('identity_security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->uuid('request_id');
            $table->string('subject_hash', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_identity_security_events_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Identity security events are append-only' USING ERRCODE = '23514';
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER identity_security_events_append_only BEFORE UPDATE OR DELETE ON identity_security_events
                FOR EACH ROW EXECUTE FUNCTION ucg_identity_security_events_append_only()");
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER identity_security_events_append_only_{$operation} BEFORE {$operation} ON identity_security_events
                    BEGIN SELECT RAISE(ABORT, 'Identity security events are append-only'); END");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS identity_security_events_append_only ON identity_security_events;
                DROP FUNCTION IF EXISTS ucg_identity_security_events_append_only()');
        }

        Schema::dropIfExists('identity_security_events');
    }
};
