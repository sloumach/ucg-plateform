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
            throw new RuntimeException('CTR-002 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('contract_documents', function (Blueprint $table): void {
            $this->child($table);
            $table->string('reference', 80);
            $table->enum('kind', ['original', 'amendment']);
            $table->text('title');
            $table->uuid('original_document_id')->nullable();
            $table->date('effective_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'contract_id', 'id'], 'contract_document_identity');
            $table->unique(['organization_id', 'contract_id', 'reference'], 'contract_document_reference');
            $table->foreign(['organization_id', 'contract_id', 'original_document_id'], 'contract_document_origin')
                ->references(['organization_id', 'contract_id', 'id'])->on('contract_documents')->restrictOnDelete();
            $table->index(['organization_id', 'contract_id', 'created_at', 'id'], 'contract_documents_page');
        });
        DB::statement("CREATE UNIQUE INDEX contract_document_one_original ON contract_documents (organization_id, contract_id) WHERE kind='original'");
        Schema::create('contract_document_versions', function (Blueprint $table): void {
            $this->child($table);
            $table->uuid('document_id');
            $table->foreign(['organization_id', 'contract_id', 'document_id'], 'contract_version_document')
                ->references(['organization_id', 'contract_id', 'id'])->on('contract_documents')->restrictOnDelete();
            $table->uuid('artifact_id');
            $table->foreign(['organization_id', 'artifact_id'], 'contract_version_artifact')
                ->references(['organization_id', 'id'])->on('tenant_artifacts')->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->enum('purpose', ['draft', 'signed_copy']);
            $table->text('filename');
            $table->string('sha256', 64);
            $table->unsignedInteger('bytes');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'contract_id', 'document_id', 'id'], 'contract_version_identity');
            $table->unique(['organization_id', 'document_id', 'number'], 'contract_version_number');
            $table->unique(['organization_id', 'artifact_id'], 'contract_version_artifact_once');
        });
        Schema::create('contract_document_signatures', function (Blueprint $table): void {
            $this->child($table);
            $table->uuid('document_id');
            $table->foreign(['organization_id', 'contract_id', 'document_id'], 'contract_signature_document')
                ->references(['organization_id', 'contract_id', 'id'])->on('contract_documents')->restrictOnDelete();
            $table->uuid('document_version_id');
            $table->foreign(['organization_id', 'contract_id', 'document_id', 'document_version_id'], 'contract_signature_version')
                ->references(['organization_id', 'contract_id', 'document_id', 'id'])->on('contract_document_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->string('sha256', 64);
            $table->date('signed_on');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'document_id'], 'contract_signed_version_once');
        });
        $this->guards();
    }

    private function child(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
        $table->uuid('contract_id');
        $table->foreign(['organization_id', 'contract_id'])->references(['organization_id', 'id'])->on('contracts')->restrictOnDelete();
        $table->unique(['organization_id', 'id']);
    }

    private function guards(): void
    {
        $day = DB::getDriverName() === 'pgsql' ? "(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date" : "date('now')";
        $signed = DB::getDriverName() === 'pgsql' ? 'NEW.signed_on' : 'date(NEW.signed_on)';
        $hashInvalid = DB::getDriverName() === 'pgsql' ? "NEW.sha256 !~ '^[0-9a-f]{64}$'" : "NEW.sha256 GLOB '*[^0-9a-f]*'";
        $closed = "EXISTS (SELECT 1 FROM contracts c WHERE c.organization_id=NEW.organization_id AND c.id=NEW.contract_id AND c.status='archived')";
        $unsigned = "NOT EXISTS (SELECT 1 FROM contracts c WHERE c.organization_id=NEW.organization_id AND c.id=NEW.contract_id AND c.status IN ('signed','active','ended','terminated'))";
        $doc = "{$closed}
            OR (NEW.kind='original' AND (NEW.original_document_id IS NOT NULL OR NEW.effective_on IS NOT NULL))
            OR (NEW.kind='amendment' AND (NEW.original_document_id IS NULL OR {$unsigned}
                OR NOT EXISTS (SELECT 1 FROM contract_documents d JOIN contract_document_signatures s ON s.organization_id=d.organization_id AND s.document_id=d.id
                    WHERE d.organization_id=NEW.organization_id AND d.contract_id=NEW.contract_id AND d.id=NEW.original_document_id AND d.kind='original')))";
        $this->install('contract_documents', $doc);
        $version = "{$closed}
            OR NEW.number<1 OR NEW.number>100 OR NEW.bytes<1 OR NEW.bytes>5242880 OR length(NEW.sha256)!=64 OR {$hashInvalid}
            OR NEW.number != (SELECT COALESCE(MAX(v.number),0)+1 FROM contract_document_versions v WHERE v.organization_id=NEW.organization_id AND v.document_id=NEW.document_id)
            OR EXISTS (SELECT 1 FROM contract_document_signatures s WHERE s.organization_id=NEW.organization_id AND s.document_id=NEW.document_id)
            OR NOT EXISTS (SELECT 1 FROM tenant_artifacts a WHERE a.organization_id=NEW.organization_id AND a.id=NEW.artifact_id AND a.ready)
            OR (NEW.purpose='signed_copy' AND {$unsigned})
            OR (NEW.purpose='draft' AND EXISTS (SELECT 1 FROM contract_documents d JOIN contracts c ON c.organization_id=d.organization_id AND c.id=d.contract_id
                WHERE d.organization_id=NEW.organization_id AND d.id=NEW.document_id AND d.kind='original' AND c.status NOT IN ('draft','in_review')))";
        $this->install('contract_document_versions', $version);
        $signature = "{$closed} OR {$unsigned} OR {$signed}>{$day} OR {$signed}<'1900-01-01'
            OR NOT EXISTS (SELECT 1 FROM contract_document_versions v WHERE v.organization_id=NEW.organization_id AND v.contract_id=NEW.contract_id AND v.document_id=NEW.document_id
                AND v.id=NEW.document_version_id AND v.purpose='signed_copy' AND v.number=NEW.version_number AND v.sha256=NEW.sha256)
            OR EXISTS (SELECT 1 FROM contract_documents d JOIN contracts c ON c.organization_id=d.organization_id AND c.id=d.contract_id
                WHERE d.organization_id=NEW.organization_id AND d.id=NEW.document_id AND d.kind='original' AND NEW.signed_on!=c.signed_on)";
        $this->install('contract_document_signatures', $signature);
    }

    /** @param literal-string $table
     * @param  literal-string  $insert
     */
    private function install(string $table, string $insert): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='INSERT' THEN
                    PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                    IF ({$insert}) THEN RAISE EXCEPTION 'Invalid contract document insert' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'Contract documents retained and immutable' USING ERRCODE='23514';
                END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$insert}
                BEGIN SELECT RAISE(ABORT, 'Invalid contract document insert'); END");
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("CREATE TRIGGER {$table}_{$operation} BEFORE {$operation} ON {$table}
                    BEGIN SELECT RAISE(ABORT, 'Contract documents retained and immutable'); END");
            }
        }
    }

    public function down(): void
    {
        $tables = ['contract_document_signatures', 'contract_document_versions', 'contract_documents'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('CTR-002 rollback cannot discard document history. Use a forward fix.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        foreach ($tables as $table) {
            Schema::drop($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION ucg_{$table}_guard()");
            }
        }
    }
};
