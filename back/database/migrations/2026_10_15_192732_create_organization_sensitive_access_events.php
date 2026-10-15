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
            throw new RuntimeException('CTR-006 requires SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('organization_sensitive_access_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', ['contract.listed', 'contract.viewed', 'contract.history_viewed', 'contract.financial_viewed',
                'contract.amendment_financial_viewed', 'contract.documents_viewed', 'contract.versions_viewed', 'contract.approvals_viewed',
                'contract.scan_history_viewed', 'contract.download_link_created', 'contract.document_downloaded',
                'contract.reminders_viewed', 'contract.reminder_history_viewed', 'contract.reminder_inbox_viewed',
                'tenant.export_requested', 'tenant.export_retried', 'tenant.export_generated', 'tenant.export_read']);
            $table->enum('target_type', ['contract_collection', 'contract', 'contract_document', 'contract_document_version', 'organization_export']);
            $table->uuid('target_id');
            $table->enum('reason_code', ['operational', 'internal_control', 'legal_review', 'operator_portability']);
            $table->uuid('request_id');
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('occurred_at');
            $table->unique(['organization_id', 'id'], 'sensitive_access_identity');
            $table->index(['organization_id', 'occurred_at', 'id'], 'sensitive_access_timeline');
            $table->index(['organization_id', 'actor_user_id', 'occurred_at', 'id'], 'sensitive_access_actor');
            $table->index(['organization_id', 'action', 'occurred_at', 'id'], 'sensitive_access_action');
        });
        $shape = "NEW.target_type != CASE
            WHEN NEW.action IN ('contract.listed','contract.reminder_inbox_viewed') THEN 'contract_collection'
            WHEN NEW.action IN ('contract.amendment_financial_viewed','contract.versions_viewed','contract.approvals_viewed') THEN 'contract_document'
            WHEN NEW.action IN ('contract.scan_history_viewed','contract.download_link_created','contract.document_downloaded') THEN 'contract_document_version'
            WHEN NEW.action IN ('tenant.export_requested','tenant.export_retried','tenant.export_generated','tenant.export_read') THEN 'organization_export'
            ELSE 'contract' END";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_sensitive_access_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP!='INSERT' THEN RAISE EXCEPTION 'Sensitive audit is append-only' USING ERRCODE='23514'; END IF;
                IF ({$shape}) THEN RAISE EXCEPTION 'Invalid sensitive audit target' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER sensitive_access_guard BEFORE INSERT OR UPDATE OR DELETE ON organization_sensitive_access_events
                FOR EACH ROW EXECUTE FUNCTION ucg_sensitive_access_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER sensitive_access_shape BEFORE INSERT ON organization_sensitive_access_events WHEN {$shape}
                BEGIN SELECT RAISE(ABORT,'Invalid sensitive audit target'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER sensitive_access_{$operation} BEFORE {$operation} ON organization_sensitive_access_events
                    BEGIN SELECT RAISE(ABORT,'Sensitive audit is append-only'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('organization_sensitive_access_events')->exists()) {
            throw new RuntimeException('CTR-006 rollback cannot discard access history. Use a forward fix.');
        }
        Schema::drop('organization_sensitive_access_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_sensitive_access_guard()');
        }
    }
};
