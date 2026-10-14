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
            throw new RuntimeException('CTR-003 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('contract_document_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('contract_id');
            $table->uuid('document_id');
            $table->uuid('document_version_id');
            $table->foreign(['organization_id', 'contract_id', 'document_id', 'document_version_id'], 'contract_scan_version')
                ->references(['organization_id', 'contract_id', 'document_id', 'id'])->on('contract_document_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('sha256', 64);
            $table->string('policy', 80);
            $table->enum('status', ['queued', 'processing', 'clean', 'rejected', 'failed']);
            $table->string('reason', 40)->nullable();
            $table->string('engine', 160)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'document_version_id', 'attempt'], 'contract_scan_attempt');
        });
        $diff = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $shape = "(NEW.status IN ('queued','processing') AND (NEW.reason IS NOT NULL OR NEW.finished_at IS NOT NULL OR NEW.engine IS NOT NULL))
            OR (NEW.status='queued' AND NEW.started_at IS NOT NULL)
            OR (NEW.status='processing' AND NEW.started_at IS NULL)
            OR (NEW.status IN ('clean','rejected','failed') AND NEW.finished_at IS NULL)
            OR (NEW.status='clean' AND (NEW.reason IS NOT NULL OR NEW.engine IS NULL OR NEW.engine='' OR NEW.started_at IS NULL))
            OR (NEW.status IN ('rejected','failed') AND (NEW.reason IS NULL OR NEW.reason NOT IN ('malware','invalid_pdf','integrity','scanner_unavailable','scanner_response','scanner_database_stale','dispatch_failed','worker_failed','timed_out')))
            OR (NEW.invalidated_at IS NOT NULL AND NEW.status!='clean')
            OR (NEW.started_at IS NOT NULL AND NEW.started_at<NEW.requested_at)
            OR (NEW.finished_at IS NOT NULL AND NEW.finished_at<NEW.requested_at)
            OR (NEW.invalidated_at IS NOT NULL AND NEW.invalidated_at<NEW.finished_at)
            OR (NEW.finished_at IS NOT NULL AND NEW.started_at IS NOT NULL AND NEW.finished_at<NEW.started_at)";
        $insert = "({$shape}) OR NEW.status!='queued' OR NEW.invalidated_at IS NOT NULL OR NEW.attempt<1 OR NEW.attempt>1000
            OR NEW.attempt!=(SELECT COALESCE(MAX(s.attempt),0)+1 FROM contract_document_scans s WHERE s.organization_id=NEW.organization_id AND s.document_version_id=NEW.document_version_id)
            OR NOT EXISTS (SELECT 1 FROM contract_document_versions v WHERE v.organization_id=NEW.organization_id AND v.contract_id=NEW.contract_id AND v.document_id=NEW.document_id AND v.id=NEW.document_version_id AND v.sha256=NEW.sha256)";
        $immutable = $this->changes(['id', 'organization_id', 'contract_id', 'document_id', 'document_version_id', 'attempt', 'sha256', 'policy', 'requested_by', 'requested_at'], $diff);
        $result = $this->changes(['status', 'reason', 'engine', 'started_at', 'finished_at'], $diff);
        $update = "({$shape}) OR ({$immutable}) OR NOT (
            (OLD.status='queued' AND NEW.status IN ('processing','failed') AND NEW.invalidated_at IS NULL)
            OR (OLD.status='processing' AND NEW.status IN ('clean','rejected','failed') AND NEW.started_at=OLD.started_at AND NEW.invalidated_at IS NULL)
            OR (OLD.status='clean' AND OLD.invalidated_at IS NULL AND NEW.invalidated_at IS NOT NULL AND NOT ({$result})))";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_contract_document_scans_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='INSERT' THEN
                    PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                    IF ({$insert}) THEN RAISE EXCEPTION 'Invalid document scan insert' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP='UPDATE' AND NOT ({$update}) THEN RETURN NEW; END IF;
                RAISE EXCEPTION 'Document scan history retained or invalid' USING ERRCODE='23514';
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER contract_document_scans_guard BEFORE INSERT OR UPDATE OR DELETE ON contract_document_scans FOR EACH ROW EXECUTE FUNCTION ucg_contract_document_scans_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER contract_document_scans_insert BEFORE INSERT ON contract_document_scans WHEN {$insert}
                BEGIN SELECT RAISE(ABORT,'Invalid document scan insert'); END");
            DB::unprepared("CREATE TRIGGER contract_document_scans_update BEFORE UPDATE ON contract_document_scans WHEN {$update}
                BEGIN SELECT RAISE(ABORT,'Invalid document scan update'); END");
            DB::unprepared("CREATE TRIGGER contract_document_scans_delete BEFORE DELETE ON contract_document_scans
                BEGIN SELECT RAISE(ABORT,'Document scan history retained'); END");
        }
    }

    /** @param list<literal-string> $fields
     * @param  literal-string  $diff
     * @return literal-string
     */
    private function changes(array $fields, string $diff): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = "NEW.{$field} {$diff} OLD.{$field}";
        }

        return implode(' OR ', $parts);
    }

    public function down(): void
    {
        if (DB::table('contract_document_scans')->exists()) {
            throw new RuntimeException('CTR-003 rollback cannot discard document scans. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::drop('contract_document_scans');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_contract_document_scans_guard()');
        }
    }
};
