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
            throw new RuntimeException('REP-004 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::table('tenant_artifacts', function (Blueprint $t): void {
            $t->timestampTz('expires_at')->nullable();
            $t->index(['expires_at', 'updated_at', 'id'], 'ta_expiry');
        });
        Schema::create('report_exports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('card', 32);
            $t->string('view', 24);
            $t->string('timezone', 64);
            $t->date('from_date');
            $t->date('to_date');
            $t->string('status', 12);
            // Artifact retention is independent; expiry can remove the ledger entry.
            $t->uuid('artifact_id')->nullable();
            $t->unsignedBigInteger('source_version')->nullable();
            $t->unsignedInteger('row_count')->nullable();
            $t->string('digest', 64)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('generated_at')->nullable();
            $t->timestampsTz();
            $t->unique(['organization_id', 'id'], 're_tenant');
            $t->unique(['organization_id', 'actor_user_id', 'request_key'], 're_request');
            $t->index(['organization_id', 'actor_user_id', 'created_at', 'id'], 're_actor');
            $t->index(['organization_id', 'expires_at'], 're_quota');
        });
        $identity = 'NEW.id<>OLD.id OR NEW.organization_id<>OLD.organization_id OR NEW.actor_user_id<>OLD.actor_user_id OR NEW.request_key<>OLD.request_key OR NEW.card<>OLD.card OR NEW.view<>OLD.view OR NEW.timezone<>OLD.timezone OR NEW.from_date<>OLD.from_date OR NEW.to_date<>OLD.to_date OR NEW.expires_at<>OLD.expires_at';
        $shape = "NEW.status NOT IN ('queued','completed','failed') OR NEW.from_date>NEW.to_date OR NEW.expires_at<=NEW.created_at OR (NEW.status='completed' AND (NEW.artifact_id IS NULL OR NEW.source_version IS NULL OR NEW.row_count IS NULL OR NEW.row_count>500 OR NEW.digest IS NULL OR NEW.generated_at IS NULL))";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION re_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain export history'; END IF; IF TG_OP='UPDATE' AND ({$identity} OR OLD.status<>'queued') THEN RAISE EXCEPTION 'Retain export identity'; END IF; IF {$shape} THEN RAISE EXCEPTION 'Invalid export'; END IF; RETURN NEW; END; $$; CREATE TRIGGER re_guard BEFORE INSERT OR UPDATE OR DELETE ON report_exports FOR EACH ROW EXECUTE FUNCTION re_guard()");
        } else {
            foreach (['INSERT', 'UPDATE'] as $action) {
                DB::unprepared('CREATE TRIGGER re_'.strtolower($action)." BEFORE {$action} ON report_exports WHEN {$shape} BEGIN SELECT RAISE(ABORT,'Invalid export'); END");
            }
            DB::unprepared("CREATE TRIGGER re_identity BEFORE UPDATE ON report_exports WHEN {$identity} OR OLD.status<>'queued' BEGIN SELECT RAISE(ABORT,'Retain export identity'); END");
            DB::unprepared("CREATE TRIGGER re_delete BEFORE DELETE ON report_exports BEGIN SELECT RAISE(ABORT,'Retain export history'); END");
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('report_exports') && DB::table('report_exports')->exists())
            || DB::table('tenant_artifacts')->whereNotNull('expires_at')->exists()) {
            throw new RuntimeException('REP-004 refuses to discard export history or artifact expiration.');
        }
        Schema::dropIfExists('report_exports');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS re_guard()');
        }
        Schema::table('tenant_artifacts', function (Blueprint $t): void {
            $t->dropIndex('ta_expiry');
            $t->dropColumn('expires_at');
        });
    }
};
