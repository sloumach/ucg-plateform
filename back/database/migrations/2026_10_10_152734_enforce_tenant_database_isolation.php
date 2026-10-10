<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MEMBERSHIP_ACTIONS = "'membership.joined', 'membership.updated', 'membership.left'";

    private const INVITATION_ACTIONS = "'invitation.created', 'invitation.accepted', 'invitation.declined', 'invitation.revoked', 'invitation.expired'";

    public function up(): void
    {
        $this->requireSupportedDriver();

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                // Fail promptly instead of blocking tenant writes indefinitely during deployment.
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement('LOCK TABLE organization_settings, organization_memberships, organization_invitations, organization_access_events IN SHARE ROW EXCLUSIVE MODE');
            }
            $this->requireValidHistory();

            Schema::table('organization_memberships', function (Blueprint $table): void {
                $table->unique(['organization_id', 'id'], 'memberships_org_identity_unique');
            });
            Schema::table('organization_invitations', function (Blueprint $table): void {
                $table->unique(['organization_id', 'id'], 'invitations_org_identity_unique');
                $table->dropIndex('invitations_org_page_index');
            });
            Schema::table('organization_access_events', function (Blueprint $table): void {
                foreach (['membership_id' => self::MEMBERSHIP_ACTIONS, 'invitation_id' => self::INVITATION_ACTIONS] as $column => $actions) {
                    $expression = "CASE WHEN action IN ({$actions}) THEN subject_id ELSE NULL END";
                    $definition = $table->uuid($column)->nullable();
                    if (DB::getDriverName() === 'pgsql') {
                        $definition->storedAs($expression);
                    } else {
                        $definition->virtualAs($expression);
                    }
                }
                $table->foreign(['organization_id', 'membership_id'], 'access_membership_tenant_fk')
                    ->references(['organization_id', 'id'])->on('organization_memberships')->restrictOnDelete()->restrictOnUpdate();
                $table->foreign(['organization_id', 'invitation_id'], 'access_invitation_tenant_fk')
                    ->references(['organization_id', 'id'])->on('organization_invitations')->restrictOnDelete()->restrictOnUpdate();
            });
            foreach (['membership_id', 'invitation_id'] as $column) {
                DB::statement("CREATE INDEX access_{$column}_tenant_index ON organization_access_events (organization_id, {$column}) WHERE {$column} IS NOT NULL");
            }
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE organization_access_events ADD CONSTRAINT access_subject_type_check CHECK ((membership_id IS NOT NULL) <> (invitation_id IS NOT NULL))');
                DB::unprepared(<<<'SQL'
                    CREATE FUNCTION ucg_organization_settings_identity_guard() RETURNS trigger AS $$
                    BEGIN
                        IF NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN
                            RAISE EXCEPTION 'Settings tenant is immutable' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER organization_settings_identity_guard BEFORE UPDATE ON organization_settings
                    FOR EACH ROW EXECUTE FUNCTION ucg_organization_settings_identity_guard();
                    SQL);
            } else {
                // SQLite rebuilds the altered table and drops triggers; restore append-only protection.
                $this->restoreSqliteAuditGuards();
                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER access_subject_type_check BEFORE INSERT ON organization_access_events
                    WHEN NEW.membership_id IS NULL AND NEW.invitation_id IS NULL
                    BEGIN SELECT RAISE(ABORT, 'Unknown access audit subject'); END;
                    CREATE TRIGGER organization_settings_identity_guard BEFORE UPDATE ON organization_settings
                    WHEN NEW.organization_id IS NOT OLD.organization_id
                    BEGIN SELECT RAISE(ABORT, 'Settings tenant is immutable'); END;
                    SQL);
            }
        });
    }

    public function down(): void
    {
        $this->requireSupportedDriver();

        DB::transaction(function (): void {
            DB::unprepared('DROP TRIGGER IF EXISTS organization_settings_identity_guard'.(DB::getDriverName() === 'pgsql' ? ' ON organization_settings' : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION IF EXISTS ucg_organization_settings_identity_guard()');
                DB::statement('ALTER TABLE organization_access_events DROP CONSTRAINT access_subject_type_check');
            } else {
                DB::unprepared('DROP TRIGGER IF EXISTS access_subject_type_check');
            }
            Schema::table('organization_access_events', function (Blueprint $table): void {
                $table->dropIndex('access_membership_id_tenant_index');
                $table->dropIndex('access_invitation_id_tenant_index');
                $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['organization_id', 'membership_id'] : 'access_membership_tenant_fk');
                $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['organization_id', 'invitation_id'] : 'access_invitation_tenant_fk');
                $table->dropColumn(['membership_id', 'invitation_id']);
            });
            if (DB::getDriverName() === 'sqlite') {
                $this->restoreSqliteAuditGuards();
            }
            Schema::table('organization_invitations', function (Blueprint $table): void {
                $table->index(['organization_id', 'id'], 'invitations_org_page_index');
                $table->dropUnique('invitations_org_identity_unique');
            });
            Schema::table('organization_memberships', function (Blueprint $table): void {
                $table->dropUnique('memberships_org_identity_unique');
            });
        });
    }

    private function requireSupportedDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('TEN-004 : seuls PostgreSQL et SQLite sont pris en charge.');
        }
    }

    private function requireValidHistory(): void
    {
        $membershipActions = self::MEMBERSHIP_ACTIONS;
        $invitationActions = self::INVITATION_ACTIONS;
        $invalid = DB::table('organization_access_events as events')
            ->where(function (Builder $query) use ($membershipActions, $invitationActions): void {
                $query->whereRaw("events.action NOT IN ({$membershipActions}, {$invitationActions})")
                    ->orWhere(function (Builder $query) use ($membershipActions): void {
                        $query->whereRaw("events.action IN ({$membershipActions})")
                            ->whereNotExists(function (Builder $query): void {
                                $query->selectRaw('1')->from('organization_memberships as subject')
                                    ->whereColumn('subject.id', 'events.subject_id')
                                    ->whereColumn('subject.organization_id', 'events.organization_id');
                            });
                    })
                    ->orWhere(function (Builder $query) use ($invitationActions): void {
                        $query->whereRaw("events.action IN ({$invitationActions})")
                            ->whereNotExists(function (Builder $query): void {
                                $query->selectRaw('1')->from('organization_invitations as subject')
                                    ->whereColumn('subject.id', 'events.subject_id')
                                    ->whereColumn('subject.organization_id', 'events.organization_id');
                            });
                    });
            })->exists();
        if ($invalid) {
            throw new RuntimeException('TEN-004 : journal d’accès incohérent. Migration arrêtée sans modifier les données ; régularisation explicite requise.');
        }
    }

    private function restoreSqliteAuditGuards(): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::unprepared("DROP TRIGGER IF EXISTS organization_access_audit_{$operation}");
            DB::unprepared("CREATE TRIGGER organization_access_audit_{$operation} BEFORE {$operation} ON organization_access_events
                BEGIN SELECT RAISE(ABORT, 'Organization audit is immutable'); END;");
        }
    }
};
