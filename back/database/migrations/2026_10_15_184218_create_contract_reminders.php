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
            throw new RuntimeException('CTR-005 requires SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('contract_reminder_plans', function (Blueprint $table): void {
            $this->child($table);
            $table->boolean('enabled')->default(false);
            $table->date('notice_on')->nullable();
            $table->date('renewal_on')->nullable();
            $table->json('offsets');
            $table->json('channels');
            $table->uuid('version');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'contract_id']);
            $table->unique(['organization_id', 'contract_id', 'id'], 'reminder_plan_contract_identity');
            $table->index(['organization_id', 'enabled', 'checked_at', 'id'], 'reminder_plan_sweep');
        });
        Schema::create('contract_reminder_deliveries', function (Blueprint $table): void {
            $this->child($table);
            $table->uuid('plan_id');
            $table->uuid('person_id');
            $table->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('occurrence', 64);
            $table->string('kind', 16);
            $table->date('due_on');
            $table->unsignedSmallInteger('offset_days');
            $table->string('in_app_status', 16);
            $table->string('email_status', 16);
            $table->string('reason', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('version');
            $table->uuid('run_id');
            $table->timestampTz('email_started_at')->nullable();
            $table->timestampTz('email_sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['organization_id', 'contract_id', 'plan_id'], 'reminder_delivery_plan')
                ->references(['organization_id', 'contract_id', 'id'])->on('contract_reminder_plans')->restrictOnDelete();
            $table->foreign(['organization_id', 'person_id'], 'reminder_delivery_person')
                ->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->unique(['organization_id', 'occurrence']);
            $table->index(['organization_id', 'recipient_id', 'created_at'], 'reminder_inbox');
            $table->index(['organization_id', 'email_status', 'created_at'], 'reminder_email_outbox');
        });
        $diff = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $shape = "NEW.kind NOT IN ('deadline','notice','renewal') OR NEW.offset_days<0 OR NEW.offset_days>365
            OR NEW.in_app_status NOT IN ('sent','blocked','not_requested')
            OR NEW.email_status NOT IN ('pending','sending','sent','uncertain','blocked','not_requested')
            OR NEW.attempts<0 OR NEW.attempts>20";
        $this->guard('contract_reminder_deliveries', $shape,
            "({$shape}) OR NEW.id {$diff} OLD.id OR NEW.organization_id {$diff} OLD.organization_id
            OR NEW.contract_id {$diff} OLD.contract_id OR NEW.plan_id {$diff} OLD.plan_id
            OR NEW.person_id {$diff} OLD.person_id OR NEW.recipient_id {$diff} OLD.recipient_id
            OR NEW.occurrence {$diff} OLD.occurrence OR NEW.kind {$diff} OLD.kind
            OR NEW.due_on {$diff} OLD.due_on OR NEW.offset_days {$diff} OLD.offset_days
            OR NEW.created_at {$diff} OLD.created_at OR NEW.version=OLD.version");
        $this->guard('contract_reminder_plans', '1=0',
            "NEW.id {$diff} OLD.id OR NEW.organization_id {$diff} OLD.organization_id OR NEW.contract_id {$diff} OLD.contract_id OR NEW.created_at {$diff} OLD.created_at");
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

    /** @param literal-string $table
     * @param  literal-string  $insert
     * @param  literal-string  $update
     */
    private function guard(string $table, string $insert, string $update): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_ctr005_{$table}() RETURNS trigger AS $$ BEGIN
                IF TG_OP='INSERT' AND ({$insert}) THEN RAISE EXCEPTION 'Invalid reminder' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND ({$update}) THEN RAISE EXCEPTION 'Invalid reminder' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Reminder retained' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER ctr005_{$table} BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_ctr005_{$table}()");
        } else {
            foreach (['insert' => $insert, 'update' => $update, 'delete' => '1=1'] as $operation => $condition) {
                DB::unprepared("CREATE TRIGGER ctr005_{$table}_{$operation} BEFORE {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Invalid reminder'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('contract_reminder_plans')->exists() || DB::table('contract_reminder_deliveries')->exists()) {
            throw new RuntimeException('CTR-005 rollback cannot discard reminder settings or history. Use a forward fix.');
        }
        foreach (['contract_reminder_deliveries', 'contract_reminder_plans'] as $table) {
            Schema::drop($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION ucg_ctr005_{$table}()");
            }
        }
    }
};
