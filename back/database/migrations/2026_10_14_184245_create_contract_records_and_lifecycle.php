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
            throw new RuntimeException('CTR-001 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('reference', 80);
            $table->enum('type', ['player', 'coach', 'manager', 'analyst', 'contributor', 'sponsor']);
            $table->text('title');
            $table->uuid('responsible_person_id');
            $table->foreign(['organization_id', 'responsible_person_id'], 'contract_responsible_tenant')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->text('responsible_name');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->enum('status', ['draft', 'in_review', 'approved', 'sent', 'signed', 'active', 'ended', 'terminated', 'archived']);
            $table->uuid('version');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->date('signed_on')->nullable();
            $table->text('signature_reference')->nullable();
            $table->timestampTz('signature_recorded_at')->nullable();
            $table->date('terminated_on')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status', 'starts_on', 'id'], 'contracts_status_page');
            $table->index(['organization_id', 'responsible_person_id', 'id'], 'contracts_responsible_page');
        });
        Schema::create('contract_parties', function (Blueprint $table): void {
            $this->child($table);
            $table->enum('kind', ['person', 'external']);
            $table->uuid('person_id')->nullable();
            $table->foreign(['organization_id', 'person_id'], 'contract_party_person_tenant')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->text('name');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['organization_id', 'contract_id', 'position'], 'contract_party_position');
            $table->unique(['organization_id', 'contract_id', 'person_id'], 'contract_party_person');
        });
        Schema::create('contract_obligations', function (Blueprint $table): void {
            $this->child($table);
            $table->text('title');
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('position');
            $table->enum('status', ['pending', 'completed', 'waived']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'contract_id', 'position'], 'contract_obligation_position');
            $table->index(['organization_id', 'contract_id', 'status', 'id'], 'contract_obligations_pending');
        });
        Schema::create('contract_transitions', function (Blueprint $table): void {
            $this->child($table);
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->uuid('contract_version');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->unique(['organization_id', 'contract_id', 'contract_version'], 'contract_transition_version');
            $table->index(['organization_id', 'contract_id', 'occurred_at', 'id'], 'contract_transitions_page');
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
        $pg = DB::getDriverName() === 'pgsql';
        $diff = $pg ? 'IS DISTINCT FROM' : 'IS NOT';
        $day = $pg ? "(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date" : "date('now')";

        $edges = [
            'draft' => ['in_review', 'archived'], 'in_review' => ['draft', 'approved', 'archived'],
            'approved' => ['in_review', 'sent'], 'sent' => ['in_review', 'signed'],
            'signed' => ['active', 'ended', 'terminated'], 'active' => ['ended', 'terminated'],
            'ended' => ['archived'], 'terminated' => ['archived'],
        ];
        $clauses = [];
        $historyClauses = [];
        foreach ($edges as $from => $targets) {
            $clauses[] = "(OLD.status='{$from}' AND NEW.status IN ('".implode("','", $targets)."'))";
            $historyClauses[] = "(NEW.from_status='{$from}' AND NEW.to_status IN ('".implode("','", $targets)."'))";
        }
        $allowed = implode(' OR ', $clauses);
        $historyEdges = implode(' OR ', $historyClauses);
        $start = $this->sqlDate('NEW.starts_on', $pg);
        $end = $this->sqlDate('NEW.ends_on', $pg);
        $signed = $this->sqlDate('NEW.signed_on', $pg);
        $terminated = $this->sqlDate('NEW.terminated_on', $pg);
        $invalid = "(NEW.ends_on IS NOT NULL AND (NEW.starts_on IS NULL OR {$end}<{$start}))
            OR (NEW.status IN ('approved','sent','signed','active','ended','terminated') AND (NEW.starts_on IS NULL OR NEW.approved_by IS NULL OR NEW.approved_at IS NULL))
            OR (NEW.status IN ('signed','active','ended','terminated') AND (NEW.signed_on IS NULL OR NEW.signature_reference IS NULL OR NEW.signature_recorded_at IS NULL))
            OR ((NEW.signed_on IS NULL) != (NEW.signature_reference IS NULL)) OR ((NEW.signed_on IS NULL) != (NEW.signature_recorded_at IS NULL))
            OR (NEW.signed_on IS NOT NULL AND {$signed}>{$day})
            OR (NEW.terminated_on IS NOT NULL AND (NEW.signed_on IS NULL OR {$terminated}<{$signed} OR {$terminated}>{$day}))
            OR (NEW.status='terminated' AND NEW.terminated_on IS NULL)
            OR (NEW.terminated_on IS NOT NULL AND NEW.status NOT IN ('terminated','archived'))";
        $immutable = $this->changes(['id', 'organization_id', 'reference', 'type', 'created_by'], $diff);
        $terms = $this->changes(['title', 'starts_on', 'ends_on', 'prepared_by'], $diff);
        $signature = $this->changes(['signed_on', 'signature_reference', 'signature_recorded_at'], $diff);
        $update = "({$immutable}) OR OLD.status='archived'
            OR (OLD.status NOT IN ('draft','in_review') AND ({$terms}))
            OR (NEW.status!=OLD.status AND NOT ({$allowed}))
            OR (OLD.signed_on IS NOT NULL AND ({$signature}))
            OR (OLD.terminated_on IS NOT NULL AND NEW.terminated_on {$diff} OLD.terminated_on)
            OR ((".$this->changes(['approved_by', 'approved_at'], $diff).") AND NOT (
                (OLD.status='in_review' AND NEW.status='approved') OR (NEW.status IN ('draft','in_review') AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL)))
            OR (OLD.signed_on IS NULL AND NEW.signed_on IS NOT NULL AND NOT (OLD.status='sent' AND NEW.status='signed'))
            OR (NEW.status='approved' AND (NEW.approved_by=NEW.prepared_by OR NOT EXISTS (SELECT 1 FROM contract_parties WHERE contract_id=NEW.id AND organization_id=NEW.organization_id)))
            OR (NEW.status='active' AND OLD.status!='active' AND ({$start}>{$day} OR (NEW.ends_on IS NOT NULL AND {$end}<{$day})))
            OR (NEW.status='ended' AND (NEW.ends_on IS NULL OR {$end}>={$day}))
            OR (NEW.status='archived' AND EXISTS (SELECT 1 FROM contract_obligations WHERE contract_id=NEW.id AND organization_id=NEW.organization_id AND status='pending'))";
        $this->install('contracts', $invalid." OR NEW.status!='draft' OR NEW.signed_on IS NOT NULL OR NEW.approved_by IS NOT NULL OR NEW.terminated_on IS NOT NULL", "({$invalid}) OR ({$update})", '1=1');
        $partyShape = "(NEW.kind='person' AND NEW.person_id IS NULL) OR (NEW.kind='external' AND NEW.person_id IS NOT NULL) OR NEW.position<1 OR NEW.position>10";
        $newEditable = "EXISTS (SELECT 1 FROM contracts WHERE id=NEW.contract_id AND organization_id=NEW.organization_id AND status IN ('draft','in_review'))";
        $oldEditable = "EXISTS (SELECT 1 FROM contracts WHERE id=OLD.contract_id AND organization_id=OLD.organization_id AND status IN ('draft','in_review'))";
        $childIdentity = $this->changes(['id', 'organization_id', 'contract_id', 'position'], $diff);
        $this->install('contract_parties', "({$partyShape}) OR NOT ({$newEditable})", "({$partyShape}) OR ({$childIdentity}) OR NOT ({$newEditable})", "NOT ({$oldEditable})");
        $newOpen = "EXISTS (SELECT 1 FROM contracts WHERE id=NEW.contract_id AND organization_id=NEW.organization_id AND status!='archived')";
        $this->install('contract_obligations', "NEW.status!='pending' OR NEW.position<1 OR NEW.position>50 OR NOT ({$newEditable})",
            "({$childIdentity}) OR NOT ({$newOpen}) OR ((".$this->changes(['title', 'due_on'], $diff).") AND NOT ({$newEditable}))",
            "OLD.status!='pending' OR NOT ({$oldEditable})");
        $this->install('contract_transitions', "NOT EXISTS (SELECT 1 FROM contracts WHERE id=NEW.contract_id AND organization_id=NEW.organization_id AND status=NEW.to_status AND version=NEW.contract_version)
            OR NOT ((NEW.from_status IS NULL AND NEW.to_status='draft') OR ({$historyEdges}))", '1=1', '1=1');
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

    /** @param literal-string $field
     * @return literal-string
     */
    private function sqlDate(string $field, bool $postgres): string
    {
        return $postgres ? $field : "date({$field})";
    }

    /** Only server-owned schema fragments, never request data.
     * @param  literal-string  $table
     * @param  literal-string  $insert
     * @param  literal-string  $update
     * @param  literal-string  $delete
     */
    private function install(string $table, string $insert, string $update, string $delete): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='INSERT' AND ({$insert}) THEN RAISE EXCEPTION 'Invalid contract insert' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND ({$update}) THEN RAISE EXCEPTION 'Immutable or invalid contract update' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' AND ({$delete}) THEN RAISE EXCEPTION 'Contract history retained' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
        } else {
            foreach (['insert' => $insert, 'update' => $update, 'delete' => $delete] as $operation => $condition) {
                DB::unprepared("CREATE TRIGGER {$table}_{$operation} BEFORE {$operation} ON {$table} WHEN {$condition}
                    BEGIN SELECT RAISE(ABORT, 'Immutable or invalid contract data'); END");
            }
        }
    }

    public function down(): void
    {
        $tables = ['contract_transitions', 'contract_obligations', 'contract_parties', 'contracts'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('CTR-001 rollback cannot discard contract history. Use a forward fix.');
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
