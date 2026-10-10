<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['active', 'suspended', 'revoked'])->default('active');
            $table->json('roles');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status', 'organization_id'], 'memberships_user_access_index');
        });
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('email', 254);
            $table->json('roles');
            $table->enum('status', ['pending', 'accepted', 'declined', 'revoked', 'expired'])->default('pending');
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('responded_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email', 'status'], 'invitations_org_recipient_index');
            $table->index(['email', 'id'], 'invitations_inbox_index');
            $table->index(['organization_id', 'id'], 'invitations_org_page_index');
        });
        Schema::create('organization_access_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->uuid('subject_id');
            $table->json('before')->nullable();
            $table->json('after');
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->index(['organization_id', 'occurred_at', 'id'], 'organization_access_timeline_index');
        });
        DB::statement("CREATE UNIQUE INDEX organization_invitation_pending_unique ON organization_invitations (organization_id, email) WHERE status = 'pending'");
        foreach (['organization_memberships', 'organization_invitations'] as $table) {
            $recipient = $table === 'organization_memberships' ? 'user_id' : 'email';
            $issuerPg = $table === 'organization_invitations' ? ' OR NEW.invited_by IS DISTINCT FROM OLD.invited_by' : '';
            $issuerSqlite = $table === 'organization_invitations' ? ' OR NEW.invited_by IS NOT OLD.invited_by' : '';
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_identity_guard() RETURNS trigger AS $$
                    BEGIN
                        IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                            OR NEW.{$recipient} IS DISTINCT FROM OLD.{$recipient}{$issuerPg} THEN
                            RAISE EXCEPTION 'Access identity is immutable' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_identity_guard BEFORE UPDATE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_identity_guard()");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_period_check CHECK (ends_at IS NULL OR ends_at > starts_at)");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_identity_guard BEFORE UPDATE ON {$table}
                    WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.{$recipient} IS NOT OLD.{$recipient}{$issuerSqlite}
                    BEGIN SELECT RAISE(ABORT, 'Access identity is immutable'); END;");
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_period_{$operation} BEFORE {$operation} ON {$table}
                        WHEN NEW.ends_at IS NOT NULL AND NEW.ends_at <= NEW.starts_at
                        BEGIN SELECT RAISE(ABORT, 'Invalid membership period'); END;");
                }
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER organization_access_audit_guard BEFORE UPDATE OR DELETE ON organization_access_events
                FOR EACH ROW EXECUTE FUNCTION ucg_organization_audit_guard()');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER organization_access_audit_{$operation} BEFORE {$operation} ON organization_access_events
                    BEGIN SELECT RAISE(ABORT, 'Organization audit is immutable'); END;");
            }
        }
    }

    /** Development rollback drops access history; production should use forward fixes. */
    public function down(): void
    {
        Schema::dropIfExists('organization_access_events');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_memberships');
        if (DB::getDriverName() === 'pgsql') {
            foreach (['organization_memberships', 'organization_invitations'] as $table) {
                DB::unprepared("DROP FUNCTION IF EXISTS ucg_{$table}_identity_guard()");
            }
        }
    }
};
