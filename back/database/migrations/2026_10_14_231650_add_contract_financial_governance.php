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
            throw new RuntimeException('CTR-004 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        if (DB::getDriverName() === 'sqlite') {
            // Native additive changes preserve the existing cross-table triggers.
            DB::statement('ALTER TABLE contracts ADD COLUMN signature_separation_required INTEGER NOT NULL DEFAULT 0 CHECK (signature_separation_required IN (0,1))');
            DB::statement('ALTER TABLE contracts ADD COLUMN signature_recorded_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT');
        } else {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->boolean('signature_separation_required')->default(false);
                $table->foreignId('signature_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            });
        }
        Schema::create('contract_financial_terms', function (Blueprint $table): void {
            $this->child($table);
            $table->uuid('document_id')->nullable();
            $table->uuid('scope_id');
            $table->text('terms');
            $table->uuid('version');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'scope_id']);
            $table->foreign(['organization_id', 'contract_id', 'document_id'], 'contract_finance_document')
                ->references(['organization_id', 'contract_id', 'id'])->on('contract_documents')->restrictOnDelete();
        });
        Schema::create('contract_amendment_approvals', function (Blueprint $table): void {
            $this->child($table);
            $table->uuid('document_id');
            $table->uuid('document_version_id');
            $table->uuid('financial_version')->nullable();
            $table->unsignedSmallInteger('number');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'document_id', 'number']);
            $table->foreign(['organization_id', 'contract_id', 'document_id', 'document_version_id'], 'contract_amendment_preparation')
                ->references(['organization_id', 'contract_id', 'document_id', 'id'])->on('contract_document_versions')->restrictOnDelete();
        });
        Schema::create('contract_approved_copies', function (Blueprint $table): void {
            $this->child($table);
            $table->uuid('document_id');
            $table->uuid('document_version_id');
            $table->uuid('approval_id');
            $table->unique(['organization_id', 'document_version_id']);
            $table->foreign(['organization_id', 'contract_id', 'document_id', 'document_version_id'], 'contract_approved_copy_version')
                ->references(['organization_id', 'contract_id', 'document_id', 'id'])->on('contract_document_versions')->restrictOnDelete();
            $table->foreign(['organization_id', 'approval_id'], 'contract_approved_copy_approval')
                ->references(['organization_id', 'id'])->on('contract_amendment_approvals')->restrictOnDelete();
        });
        $diff = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $open = "EXISTS (SELECT 1 FROM contracts c WHERE c.id=NEW.contract_id AND c.organization_id=NEW.organization_id AND c.status!='archived'
            AND ((NEW.document_id IS NULL AND c.status IN ('draft','in_review')) OR (NEW.document_id IS NOT NULL
                AND EXISTS (SELECT 1 FROM contract_documents d WHERE d.id=NEW.document_id AND d.organization_id=NEW.organization_id AND d.contract_id=NEW.contract_id AND d.kind='amendment')
                AND NOT EXISTS (SELECT 1 FROM contract_document_signatures s WHERE s.document_id=NEW.document_id AND s.organization_id=NEW.organization_id))))";
        $shape = "NEW.scope_id != COALESCE(NEW.document_id, NEW.contract_id) OR NOT ({$open})";
        $this->guard('contract_financial_terms', $shape,
            "({$shape}) OR NEW.id {$diff} OLD.id OR NEW.organization_id {$diff} OLD.organization_id OR NEW.contract_id {$diff} OLD.contract_id
                OR NEW.document_id {$diff} OLD.document_id OR NEW.scope_id {$diff} OLD.scope_id OR NEW.created_at {$diff} OLD.created_at OR NEW.version=OLD.version", '1=1');
        $approval = "NEW.number<1 OR NEW.number>200 OR NEW.number!=(SELECT COALESCE(MAX(a.number),0)+1 FROM contract_amendment_approvals a WHERE a.organization_id=NEW.organization_id AND a.document_id=NEW.document_id)
            OR NOT EXISTS (SELECT 1 FROM contracts c JOIN contract_documents d ON d.contract_id=c.id AND d.organization_id=c.organization_id
                JOIN contract_document_versions v ON v.document_id=d.id AND v.organization_id=d.organization_id
                WHERE c.id=NEW.contract_id AND c.organization_id=NEW.organization_id AND c.status IN ('signed','active','ended','terminated')
                AND d.id=NEW.document_id AND d.kind='amendment' AND d.created_by!=NEW.approved_by
                AND v.id=NEW.document_version_id AND v.purpose='draft' AND v.created_by!=NEW.approved_by
                AND v.number=(SELECT MAX(v2.number) FROM contract_document_versions v2 WHERE v2.organization_id=NEW.organization_id AND v2.document_id=NEW.document_id AND v2.purpose='draft'))
            OR NEW.financial_version {$diff} (SELECT f.version FROM contract_financial_terms f WHERE f.organization_id=NEW.organization_id AND f.scope_id=NEW.document_id)
            OR EXISTS (SELECT 1 FROM contract_financial_terms f WHERE f.organization_id=NEW.organization_id AND f.scope_id=NEW.document_id AND f.prepared_by=NEW.approved_by)
            OR EXISTS (SELECT 1 FROM contract_document_signatures s WHERE s.organization_id=NEW.organization_id AND s.document_id=NEW.document_id)";
        $this->guard('contract_amendment_approvals', $approval, '1=1', '1=1');
        $this->guard('contracts', '1=0',
            "(NEW.signature_separation_required {$diff} OLD.signature_separation_required AND OLD.status NOT IN ('draft','in_review'))
            OR (OLD.status='in_review' AND NEW.status='approved' AND EXISTS (SELECT 1 FROM contract_financial_terms f WHERE f.organization_id=NEW.organization_id AND f.scope_id=NEW.id AND f.prepared_by=NEW.approved_by))
            OR (NEW.signature_recorded_by {$diff} OLD.signature_recorded_by AND NOT (OLD.status='sent' AND NEW.status='signed' AND NEW.signature_recorded_by IS NOT NULL))
            OR (OLD.status='sent' AND NEW.status='signed' AND (NEW.signature_recorded_by IS NULL OR (NEW.signature_separation_required AND (NEW.signature_recorded_by=NEW.prepared_by OR NEW.signature_recorded_by=NEW.approved_by
                OR EXISTS (SELECT 1 FROM contract_financial_terms f WHERE f.organization_id=NEW.organization_id AND f.scope_id=NEW.id AND f.prepared_by=NEW.signature_recorded_by)))))", '1=0');
        $currentApproval = "EXISTS (SELECT 1 FROM contract_amendment_approvals a WHERE a.id=NEW.approval_id AND a.organization_id=NEW.organization_id AND a.contract_id=NEW.contract_id AND a.document_id=NEW.document_id
            AND a.number=(SELECT MAX(a2.number) FROM contract_amendment_approvals a2 WHERE a2.organization_id=NEW.organization_id AND a2.document_id=NEW.document_id)
            AND a.document_version_id=(SELECT v.id FROM contract_document_versions v WHERE v.organization_id=NEW.organization_id AND v.document_id=NEW.document_id AND v.purpose='draft' ORDER BY v.number DESC LIMIT 1)
            AND NOT (a.financial_version {$diff} (SELECT f.version FROM contract_financial_terms f WHERE f.organization_id=NEW.organization_id AND f.scope_id=NEW.document_id)))";
        $amendment = "EXISTS (SELECT 1 FROM contract_documents d WHERE d.id=NEW.document_id AND d.organization_id=NEW.organization_id AND d.kind='amendment')";
        $this->guard('contract_approved_copies', "NOT ({$currentApproval}) OR NOT ({$amendment}) OR NOT EXISTS (SELECT 1 FROM contract_document_versions v WHERE v.organization_id=NEW.organization_id AND v.document_id=NEW.document_id AND v.id=NEW.document_version_id AND v.purpose='signed_copy')", '1=1', '1=1');
        $approvedCopy = "EXISTS (SELECT 1 FROM contract_document_versions v
            JOIN contract_approved_copies b ON b.document_version_id=v.id AND b.organization_id=v.organization_id
            JOIN contract_amendment_approvals a ON a.id=b.approval_id AND a.organization_id=b.organization_id
            WHERE v.id=NEW.document_version_id AND v.document_id=NEW.document_id AND v.organization_id=NEW.organization_id
            AND a.number=(SELECT MAX(a2.number) FROM contract_amendment_approvals a2 WHERE a2.organization_id=NEW.organization_id AND a2.document_id=NEW.document_id)
            AND a.document_version_id=(SELECT v2.id FROM contract_document_versions v2 WHERE v2.organization_id=NEW.organization_id AND v2.document_id=NEW.document_id AND v2.purpose='draft' ORDER BY v2.number DESC LIMIT 1)
            AND NOT (a.financial_version {$diff} (SELECT f.version FROM contract_financial_terms f WHERE f.organization_id=NEW.organization_id AND f.scope_id=NEW.document_id)))";
        $this->guard('contract_document_signatures', "({$amendment} AND NOT ({$approvedCopy}))
            OR EXISTS (SELECT 1 FROM contracts c WHERE c.id=NEW.contract_id AND c.organization_id=NEW.organization_id AND c.signature_separation_required AND (
                (NOT ({$amendment}) AND (NEW.recorded_by=c.prepared_by OR NEW.recorded_by=c.approved_by))
                OR ({$amendment} AND (EXISTS (SELECT 1 FROM contract_amendment_approvals a WHERE a.organization_id=NEW.organization_id AND a.document_id=NEW.document_id AND a.approved_by=NEW.recorded_by AND a.number=(SELECT MAX(a2.number) FROM contract_amendment_approvals a2 WHERE a2.organization_id=NEW.organization_id AND a2.document_id=NEW.document_id))
                    OR EXISTS (SELECT 1 FROM contract_documents d WHERE d.id=NEW.document_id AND d.organization_id=NEW.organization_id AND d.created_by=NEW.recorded_by)
                    OR EXISTS (SELECT 1 FROM contract_document_versions v WHERE v.organization_id=NEW.organization_id AND v.document_id=NEW.document_id AND v.purpose='draft' AND v.created_by=NEW.recorded_by AND v.number=(SELECT MAX(v2.number) FROM contract_document_versions v2 WHERE v2.organization_id=NEW.organization_id AND v2.document_id=NEW.document_id AND v2.purpose='draft'))))
                OR EXISTS (SELECT 1 FROM contract_financial_terms f WHERE f.organization_id=NEW.organization_id AND f.scope_id=CASE WHEN {$amendment} THEN NEW.document_id ELSE NEW.contract_id END AND f.prepared_by=NEW.recorded_by)))", '1=0', '1=0');
    }

    private function child(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
        $table->uuid('contract_id');
        $table->foreign(['organization_id', 'contract_id'])->references(['organization_id', 'id'])->on('contracts')->restrictOnDelete();
        $table->unique(['organization_id', 'id']);
        $table->index(['organization_id', 'contract_id']);
    }

    /** Only migration-owned SQL fragments, never user input.
     * @param  literal-string  $table
     * @param  literal-string  $insert
     * @param  literal-string  $update
     * @param  literal-string  $delete
     */
    private function guard(string $table, string $insert, string $update, string $delete): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_ctr004_{$table}() RETURNS trigger AS $$ BEGIN
                IF TG_OP='INSERT' AND ({$insert}) THEN RAISE EXCEPTION 'Invalid contract governance' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND ({$update}) THEN RAISE EXCEPTION 'Invalid contract governance' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' AND ({$delete}) THEN RAISE EXCEPTION 'Contract governance retained' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER ctr004_{$table} BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_ctr004_{$table}()");
        } else {
            foreach (['insert' => $insert, 'update' => $update, 'delete' => $delete] as $operation => $condition) {
                DB::unprepared("CREATE TRIGGER ctr004_{$table}_{$operation} BEFORE {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Invalid contract governance'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('contract_financial_terms')->exists() || DB::table('contract_amendment_approvals')->exists() || DB::table('contract_approved_copies')->exists()
            || DB::table('contracts')->where('signature_separation_required', true)->orWhereNotNull('signature_recorded_by')->exists()) {
            throw new RuntimeException('CTR-004 rollback cannot discard financial terms or decisions. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        foreach (['contract_document_signatures', 'contract_approved_copies', 'contracts', 'contract_amendment_approvals', 'contract_financial_terms'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER ctr004_{$table} ON {$table}; DROP FUNCTION ucg_ctr004_{$table}()");
            } else {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    DB::unprepared("DROP TRIGGER ctr004_{$table}_{$operation}");
                }
            }
        }
        Schema::drop('contract_approved_copies');
        Schema::drop('contract_amendment_approvals');
        Schema::drop('contract_financial_terms');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE contracts DROP COLUMN signature_recorded_by');
            DB::statement('ALTER TABLE contracts DROP COLUMN signature_separation_required');
        } else {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->dropForeign(['signature_recorded_by']);
                $table->dropColumn(['signature_separation_required', 'signature_recorded_by']);
            });
        }
    }
};
