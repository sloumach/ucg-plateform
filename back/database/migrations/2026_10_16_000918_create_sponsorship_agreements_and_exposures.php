<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = ['sponsorship_agreement_events', 'sponsorship_exposures', 'sponsorship_agreement_terms', 'sponsorship_agreements'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('SPON-002 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('sponsorship_agreements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('sponsor_id');
            $t->foreign(['organization_id', 'sponsor_id'])->references(['organization_id', 'id'])->on('sponsors')->restrictOnDelete();
            $t->uuid('opportunity_id')->nullable();
            $t->foreign(['organization_id', 'sponsor_id', 'opportunity_id'], 'agreement_opportunity')->references(['organization_id', 'sponsor_id', 'id'])->on('sponsorship_opportunities')->restrictOnDelete();
            $t->string('reference', 80);
            $t->string('title', 160);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('contract_id')->nullable();
            $t->uuid('contract_version')->nullable();
            $t->foreign(['organization_id', 'contract_id'], 'agreement_contract')->references(['organization_id', 'id'])->on('contracts')->restrictOnDelete();
            $t->enum('status', ['draft', 'approved', 'ended', 'archived']);
            $t->uuid('version');
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->uuid('approved_financial_version')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'reference']);
            $t->index(['organization_id', 'sponsor_id', 'status', 'reference', 'id'], 'agreement_list_page');
            $t->index(['organization_id', 'status', 'starts_on', 'ends_on'], 'agreement_exclusive_period');
        });
        Schema::create('sponsorship_agreement_terms', function (Blueprint $t): void {
            $this->child($t);
            $t->text('terms');
            $t->uuid('version');
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'agreement_id']);
        });
        Schema::create('sponsorship_exposures', function (Blueprint $t): void {
            $this->child($t);
            $t->enum('target_type', ['organization', 'team', 'player']);
            $t->uuid('team_id')->nullable();
            $t->uuid('person_id')->nullable();
            $t->foreign(['organization_id', 'team_id'], 'exposure_team')->references(['organization_id', 'id'])->on('teams')->restrictOnDelete();
            $t->foreign(['organization_id', 'person_id'], 'exposure_person')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $t->text('target_label');
            $t->enum('channel', ['jersey', 'social', 'stream', 'event', 'website', 'other']);
            $t->text('description')->nullable();
            $t->text('authorization_reference');
            $t->string('exclusivity_key', 80)->nullable();
            $t->enum('status', ['active', 'archived']);
            $t->uuid('version');
            $t->timestamps();
            $t->index(['organization_id', 'agreement_id', 'status', 'created_at', 'id'], 'agreement_exposures_page');
            $t->index(['organization_id', 'exclusivity_key', 'status'], 'agreement_exclusivity');
        });
        Schema::create('sponsorship_agreement_events', function (Blueprint $t): void {
            $this->child($t);
            $t->enum('action', ['created', 'updated', 'exposure_saved', 'financial_saved', 'approved', 'ended', 'archived', 'financial_viewed']);
            $t->enum('from_status', ['draft', 'approved', 'ended', 'archived'])->nullable();
            $t->enum('to_status', ['draft', 'approved', 'ended', 'archived']);
            $t->uuid('agreement_version');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('occurred_at');
            $t->index(['organization_id', 'agreement_id', 'occurred_at', 'id'], 'agreement_history_page');
        });
        $this->guards();
    }

    private function child(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
        $t->uuid('agreement_id');
        $t->foreign(['organization_id', 'agreement_id'])->references(['organization_id', 'id'])->on('sponsorship_agreements')->restrictOnDelete();
        $t->unique(['organization_id', 'id']);
    }

    private function guards(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        $diff = $pg ? 'IS DISTINCT FROM' : 'IS NOT';
        foreach (self::TABLES as $table) {
            $identity = ['id', 'organization_id', ...($table === 'sponsorship_agreements' ? ['sponsor_id', 'opportunity_id', 'reference'] : ['agreement_id'])];
            if ($table === 'sponsorship_exposures') {
                $identity = [...$identity, 'target_type', 'team_id', 'person_id'];
            }
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} {$diff} OLD.{$c}", $identity));
            $event = $table === 'sponsorship_agreement_events';
            $invalid = match ($table) {
                'sponsorship_agreements' => "NEW.ends_on < NEW.starts_on OR LENGTH(TRIM(NEW.title))=0
                    OR (NEW.contract_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM contracts WHERE id=NEW.contract_id AND organization_id=NEW.organization_id AND type='sponsor'))
                    OR (NEW.contract_id IS NULL AND NEW.contract_version IS NOT NULL) OR (NEW.contract_id IS NOT NULL AND NEW.contract_version IS NULL)
                    OR NOT EXISTS (SELECT 1 FROM sponsors WHERE id=NEW.sponsor_id AND organization_id=NEW.organization_id AND status!='archived')
                    OR (NEW.status='approved' AND (NEW.approved_by IS NULL OR NEW.approved_at IS NULL OR NEW.approved_by=NEW.prepared_by
                        OR NOT EXISTS (SELECT 1 FROM sponsorship_agreement_terms t WHERE t.organization_id=NEW.organization_id AND t.agreement_id=NEW.id AND t.version=NEW.approved_financial_version AND t.prepared_by!=NEW.approved_by)
                        OR NOT EXISTS (SELECT 1 FROM sponsorship_exposures e WHERE e.organization_id=NEW.organization_id AND e.agreement_id=NEW.id AND e.status='active')))
                    OR (NEW.status='draft' AND (NEW.approved_by IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.approved_financial_version IS NOT NULL))",
                'sponsorship_exposures' => "NOT EXISTS (SELECT 1 FROM sponsorship_agreements WHERE id=NEW.agreement_id AND organization_id=NEW.organization_id AND status='draft')
                    OR NOT ((NEW.target_type='organization' AND NEW.team_id IS NULL AND NEW.person_id IS NULL)
                        OR (NEW.target_type='team' AND NEW.team_id IS NOT NULL AND NEW.person_id IS NULL)
                        OR (NEW.target_type='player' AND NEW.team_id IS NULL AND NEW.person_id IS NOT NULL))",
                'sponsorship_agreement_terms' => "NOT EXISTS (SELECT 1 FROM sponsorship_agreements WHERE id=NEW.agreement_id AND organization_id=NEW.organization_id AND status='draft')",
                default => 'FALSE',
            };
            if (! $event) {
                if ($table === 'sponsorship_agreements') {
                    $material = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} {$diff} OLD.{$c}",
                        ['title', 'starts_on', 'ends_on', 'responsible_user_id', 'contract_id', 'contract_version', 'prepared_by', 'approved_by', 'approved_at', 'approved_financial_version']));
                    $immutable .= " OR OLD.status='archived' OR (OLD.status='ended' AND (NEW.status!='archived' OR ({$material})))
                        OR (OLD.status='approved' AND NEW.status='approved' AND (({$material}) OR NEW.version {$diff} OLD.version))";
                } elseif ($table === 'sponsorship_exposures') {
                    $immutable .= " OR OLD.status='archived'";
                }
            }
            if ($pg) {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Agreement history must be retained' USING ERRCODE='23514'; END IF;
                    PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                    IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid agreement state' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' THEN IF (".($event ? 'TRUE' : $immutable).") THEN RAISE EXCEPTION 'Agreement identity or history is immutable' USING ERRCODE='23514'; END IF; END IF;
                    RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT,'Invalid agreement state'); END");
                DB::unprepared("CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN ({$invalid}) OR (".($event ? 'TRUE' : $immutable).") BEGIN SELECT RAISE(ABORT,'Agreement identity or history is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'Agreement history must be retained'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('SPON-002 rollback cannot discard agreement history. Use a forward fix.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        foreach (self::TABLES as $table) {
            Schema::drop($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION ucg_{$table}_guard()");
            }
        }
    }
};
