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
            throw new RuntimeException('IAM-005 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('platform_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->enum('role', ['superadmin', 'support']);
            $table->boolean('active');
            $table->uuid('version');
            $table->timestampsTz();
            $table->index(['role', 'active']);
        });
        Schema::create('platform_role_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('before_role', ['superadmin', 'support'])->nullable();
            $table->enum('after_role', ['superadmin', 'support']);
            $table->boolean('before_active')->nullable();
            $table->boolean('after_active');
            $table->uuid('before_version')->nullable();
            $table->uuid('after_version');
            $table->string('reason', 500);
            $table->uuid('request_id');
            $table->unsignedTinyInteger('bootstrap_slot')->nullable()->unique();
            $table->timestampTz('occurred_at');
            $table->index(['user_id', 'occurred_at']);
        });
        Schema::create('organization_support_accesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('platform_role_version');
            $table->string('reason', 500);
            $table->json('actions');
            $table->enum('status', ['active', 'revoked']);
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'actor_user_id', 'status', 'expires_at']);
            $table->index(['actor_user_id', 'created_at', 'id']);
        });
        Schema::create('organization_support_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('support_access_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', ['opened', 'revoked', 'organization.inspect', 'access.inspect', 'organization.inspect.denied', 'access.inspect.denied']);
            $table->uuid('request_id');
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('occurred_at');
            $table->foreign(['organization_id', 'support_access_id'])->references(['organization_id', 'id'])->on('organization_support_accesses')->restrictOnDelete();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'support_access_id', 'occurred_at']);
        });
        foreach (['platform_role_events', 'organization_support_events'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_append_only() RETURNS trigger AS $$
                    BEGIN RAISE EXCEPTION 'Operator audit is append-only' USING ERRCODE = '23514'; END;
                    $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_append_only()");
            } else {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_append_only_{$operation} BEFORE {$operation} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Operator audit is append-only'); END");
                }
            }
        }
        $this->protectSupportAccesses();
        $this->protectPlatformRoles();
    }

    private function protectPlatformRoles(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_platform_role_identity() RETURNS trigger AS $$
                BEGIN
                    IF OLD.id IS DISTINCT FROM NEW.id OR OLD.user_id IS DISTINCT FROM NEW.user_id
                        OR (ROW(OLD.role, OLD.active) IS DISTINCT FROM ROW(NEW.role, NEW.active) AND OLD.version = NEW.version) THEN
                        RAISE EXCEPTION 'Platform identity is immutable and role changes require a new version' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER platform_role_identity BEFORE UPDATE ON platform_roles FOR EACH ROW EXECUTE FUNCTION ucg_platform_role_identity()");
        } else {
            DB::unprepared("CREATE TRIGGER platform_role_identity BEFORE UPDATE ON platform_roles
                WHEN OLD.id IS NOT NEW.id OR OLD.user_id IS NOT NEW.user_id
                    OR ((OLD.role IS NOT NEW.role OR OLD.active IS NOT NEW.active) AND OLD.version = NEW.version)
                BEGIN SELECT RAISE(ABORT, 'Platform identity is immutable and role changes require a new version'); END");
        }
    }

    private function protectSupportAccesses(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organization_support_accesses ADD CONSTRAINT support_period_check
                CHECK (expires_at > starts_at AND expires_at <= starts_at + interval '30 minutes')");
            DB::statement("ALTER TABLE organization_support_accesses ADD CONSTRAINT support_actions_check
                CHECK (jsonb_typeof(actions::jsonb) = 'array' AND jsonb_array_length(actions::jsonb) BETWEEN 1 AND 2
                AND actions::jsonb <@ '[\"organization.inspect\",\"access.inspect\"]'::jsonb
                AND (jsonb_array_length(actions::jsonb) = 1 OR actions::jsonb->0 <> actions::jsonb->1))");
            DB::unprepared("CREATE FUNCTION ucg_support_access_identity() RETURNS trigger AS $$
                BEGIN
                    IF ROW(OLD.id, OLD.organization_id, OLD.actor_user_id, OLD.platform_role_version, OLD.reason, OLD.actions::text, OLD.starts_at, OLD.expires_at)
                       IS DISTINCT FROM ROW(NEW.id, NEW.organization_id, NEW.actor_user_id, NEW.platform_role_version, NEW.reason, NEW.actions::text, NEW.starts_at, NEW.expires_at)
                       OR (OLD.status = 'revoked' AND NEW.status <> 'revoked') THEN
                        RAISE EXCEPTION 'Support access identity is immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER support_access_identity BEFORE UPDATE ON organization_support_accesses
                FOR EACH ROW EXECUTE FUNCTION ucg_support_access_identity()");
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER support_access_shape_{$operation} BEFORE {$operation} ON organization_support_accesses
                    WHEN NOT (julianday(NEW.expires_at) IS NOT NULL AND julianday(NEW.starts_at) IS NOT NULL
                        AND julianday(NEW.expires_at) > julianday(NEW.starts_at)
                        AND julianday(NEW.expires_at) <= julianday(NEW.starts_at) + 30.0/1440.0 + 0.00000001
                        AND json_valid(NEW.actions) AND json_type(NEW.actions) = 'array'
                        AND json_array_length(NEW.actions) BETWEEN 1 AND 2
                        AND json_extract(NEW.actions, '$[0]') IN ('organization.inspect','access.inspect')
                        AND (json_array_length(NEW.actions) = 1 OR (json_extract(NEW.actions, '$[1]') IN ('organization.inspect','access.inspect')
                            AND json_extract(NEW.actions, '$[0]') <> json_extract(NEW.actions, '$[1]'))))
                    BEGIN SELECT RAISE(ABORT, 'Invalid support period or actions'); END");
            }
            DB::unprepared("CREATE TRIGGER support_access_identity BEFORE UPDATE ON organization_support_accesses
                WHEN OLD.id IS NOT NEW.id OR OLD.organization_id IS NOT NEW.organization_id OR OLD.actor_user_id IS NOT NEW.actor_user_id
                    OR OLD.platform_role_version IS NOT NEW.platform_role_version
                    OR OLD.reason IS NOT NEW.reason OR OLD.actions IS NOT NEW.actions OR OLD.starts_at IS NOT NEW.starts_at
                    OR OLD.expires_at IS NOT NEW.expires_at OR (OLD.status = 'revoked' AND NEW.status <> 'revoked')
                BEGIN SELECT RAISE(ABORT, 'Support access identity is immutable'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('platform_role_events')->exists() || DB::table('organization_support_events')->exists()
            || DB::table('platform_roles')->exists() || DB::table('organization_support_accesses')->exists()) {
            throw new RuntimeException('Refusing to discard audited platform/support access; use a forward migration.');
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['platform_role_events', 'organization_support_events'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}; DROP FUNCTION IF EXISTS ucg_{$table}_append_only()");
            }
            DB::unprepared('DROP TRIGGER IF EXISTS support_access_identity ON organization_support_accesses; DROP FUNCTION IF EXISTS ucg_support_access_identity()');
            DB::unprepared('DROP TRIGGER IF EXISTS platform_role_identity ON platform_roles; DROP FUNCTION IF EXISTS ucg_platform_role_identity()');
        }
        Schema::dropIfExists('organization_support_events');
        Schema::dropIfExists('organization_support_accesses');
        Schema::dropIfExists('platform_role_events');
        Schema::dropIfExists('platform_roles');
    }
};
