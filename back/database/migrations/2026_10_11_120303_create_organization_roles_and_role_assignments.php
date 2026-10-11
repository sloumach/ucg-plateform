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
            throw new RuntimeException('IAM-003 supports PostgreSQL and SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('LOCK TABLE organization_access_events IN SHARE ROW EXCLUSIVE MODE');
        }
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id', 'user_id'], 'memberships_role_subject_unique');
        });
        Schema::create('organization_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('key', 80);
            $table->string('name', 120);
            $table->json('permissions');
            $table->boolean('active');
            $table->boolean('privileged');
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'key'], 'organization_role_key_unique');
            $table->unique(['organization_id', 'id'], 'organization_role_identity_unique');
            $table->index(['organization_id', 'name', 'id'], 'organization_roles_page_index');
        });
        Schema::create('organization_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('role_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('membership_id')->nullable();
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->string('status', 20);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->uuid('version');
            $table->timestamps();
            $table->foreign(['organization_id', 'role_id'], 'role_assignment_role_fk')->references(['organization_id', 'id'])->on('organization_roles')->restrictOnDelete();
            $table->foreign(['organization_id', 'membership_id', 'user_id'], 'role_assignment_member_fk')->references(['organization_id', 'id', 'user_id'])->on('organization_memberships')->restrictOnDelete();
            $table->index(['organization_id', 'user_id', 'status', 'scope_type', 'scope_id'], 'role_assignment_access_index');
            $table->index(['organization_id', 'membership_id', 'status'], 'role_assignment_membership_index');
            $table->index(['role_id', 'status'], 'role_assignment_role_index');
            $table->unique(['organization_id', 'id'], 'role_assignment_identity_unique');
            $table->index(['user_id', 'status', 'starts_at'], 'role_assignment_privileged_index');
        });
        $this->extendAudit();
        DB::statement("CREATE UNIQUE INDEX role_assignment_live_unique ON organization_role_assignments (organization_id, user_id, role_id, scope_type, scope_id) WHERE status = 'active'");
        $rules = [
            'role_assignment_period' => 'ends_at IS NULL OR ends_at > starts_at',
            'role_assignment_status' => "status IN ('active', 'suspended', 'revoked')",
            'role_assignment_scope' => "scope_type IN ('organization', 'team', 'game', 'cohort', 'event') AND (scope_type <> 'organization' OR scope_id = organization_id)",
            'role_assignment_owner' => 'membership_id IS NOT NULL OR EXISTS (SELECT 1 FROM organizations WHERE organizations.id = NEW.organization_id AND organizations.owner_user_id = NEW.user_id)',
        ];
        foreach ($rules as $name => $rule) {
            if (DB::getDriverName() === 'pgsql' && $name !== 'role_assignment_owner') {
                DB::statement("ALTER TABLE organization_role_assignments ADD CONSTRAINT {$name} CHECK ({$rule})");
            } elseif (DB::getDriverName() === 'sqlite') {
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    $expression = match ($name) {
                        'role_assignment_period' => 'NEW.ends_at IS NULL OR NEW.ends_at > NEW.starts_at',
                        'role_assignment_status' => "NEW.status IN ('active', 'suspended', 'revoked')",
                        'role_assignment_scope' => "NEW.scope_type IN ('organization', 'team', 'game', 'cohort', 'event') AND (NEW.scope_type <> 'organization' OR NEW.scope_id = NEW.organization_id)",
                        'role_assignment_owner' => 'NEW.membership_id IS NOT NULL OR EXISTS (SELECT 1 FROM organizations WHERE organizations.id = NEW.organization_id AND organizations.owner_user_id = NEW.user_id)',
                    };
                    DB::unprepared("CREATE TRIGGER {$name}_{$operation} BEFORE {$operation} ON organization_role_assignments WHEN NOT ({$expression}) BEGIN SELECT RAISE(ABORT, 'Invalid role assignment'); END;");
                }
            }
        }
        foreach (['organization_roles' => ['id', 'organization_id', 'key'], 'organization_role_assignments' => ['id', 'organization_id', 'role_id', 'user_id', 'membership_id', 'scope_type', 'scope_id']] as $table => $columns) {
            if (DB::getDriverName() === 'pgsql') {
                $condition = implode(' OR ', array_map(fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}", $columns));
                $ownerGuard = $table === 'organization_role_assignments' ? "IF NEW.membership_id IS NULL AND NOT EXISTS (SELECT 1 FROM organizations WHERE id = NEW.organization_id AND owner_user_id = NEW.user_id) THEN RAISE EXCEPTION 'Invalid role subject' USING ERRCODE = '23514'; END IF;" : '';
                DB::unprepared("CREATE FUNCTION ucg_{$table}_identity_guard() RETURNS trigger AS $$ BEGIN IF TG_OP = 'UPDATE' AND ({$condition}) THEN RAISE EXCEPTION 'Role identity is immutable' USING ERRCODE = '23514'; END IF; {$ownerGuard} RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER {$table}_identity_guard BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_identity_guard();");
            } elseif (DB::getDriverName() === 'sqlite') {
                $condition = implode(' OR ', array_map(fn (string $column): string => "NEW.{$column} IS NOT OLD.{$column}", $columns));
                DB::unprepared("CREATE TRIGGER {$table}_identity_guard BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Role identity is immutable'); END;");
            }
        }
    }

    /** Drops configured rights; production changes must prefer a forward fix. */
    public function down(): void
    {
        if (DB::table('organization_access_events')->whereIn('action', ['role.created', 'role.updated', 'role_assignment.created', 'role_assignment.updated', 'role_assignment.membership_revoked'])->exists()) {
            throw new RuntimeException('IAM-003 rollback cannot discard audited rights. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organization_access_events DROP CONSTRAINT access_subject_type_check');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS access_subject_type_check');
        }
        Schema::table('organization_access_events', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['organization_id', 'role_id']);
                $table->dropForeign(['organization_id', 'role_assignment_id']);
            } else {
                $table->dropForeign('access_role_tenant_fk');
                $table->dropForeign('access_role_assignment_tenant_fk');
            }
            $table->dropColumn(['role_id', 'role_assignment_id']);
        });
        $this->auditTypeGuard(false);
        Schema::dropIfExists('organization_role_assignments');
        Schema::dropIfExists('organization_roles');
        Schema::table('organization_memberships', fn (Blueprint $table) => $table->dropUnique('memberships_role_subject_unique'));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_organization_role_assignments_identity_guard(); DROP FUNCTION IF EXISTS ucg_organization_roles_identity_guard();');
        }
    }

    private function extendAudit(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organization_access_events DROP CONSTRAINT access_subject_type_check');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS access_subject_type_check');
        }
        Schema::table('organization_access_events', function (Blueprint $table): void {
            foreach (['role_id' => "'role.created', 'role.updated'", 'role_assignment_id' => "'role_assignment.created', 'role_assignment.updated', 'role_assignment.membership_revoked'"] as $column => $actions) {
                $expression = "CASE WHEN action IN ({$actions}) THEN subject_id ELSE NULL END";
                $definition = $table->uuid($column)->nullable();
                if (DB::getDriverName() === 'pgsql') {
                    $definition->storedAs($expression);
                } else {
                    $definition->virtualAs($expression);
                }
            }
            $table->foreign(['organization_id', 'role_id'], 'access_role_tenant_fk')->references(['organization_id', 'id'])->on('organization_roles')->restrictOnDelete();
            $table->foreign(['organization_id', 'role_assignment_id'], 'access_role_assignment_tenant_fk')->references(['organization_id', 'id'])->on('organization_role_assignments')->restrictOnDelete();
        });
        $this->auditTypeGuard(true);
    }

    private function auditTypeGuard(bool $extended): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $extra = $extended ? ' + (role_id IS NOT NULL)::int + (role_assignment_id IS NOT NULL)::int' : '';
            DB::statement("ALTER TABLE organization_access_events ADD CONSTRAINT access_subject_type_check CHECK ((membership_id IS NOT NULL)::int + (invitation_id IS NOT NULL)::int{$extra} = 1)");
        } else {
            $extra = $extended ? ' AND NEW.role_id IS NULL AND NEW.role_assignment_id IS NULL' : '';
            DB::unprepared("CREATE TRIGGER access_subject_type_check BEFORE INSERT ON organization_access_events WHEN NEW.membership_id IS NULL AND NEW.invitation_id IS NULL{$extra} BEGIN SELECT RAISE(ABORT, 'Unknown access audit subject'); END;");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS organization_access_audit_{$operation}; CREATE TRIGGER organization_access_audit_{$operation} BEFORE {$operation} ON organization_access_events BEGIN SELECT RAISE(ABORT, 'Organization audit is immutable'); END;");
            }
        }
    }
};
