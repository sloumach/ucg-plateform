<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = ['sponsorship_campaign_events', 'sponsorship_deliverables', 'sponsorship_campaigns'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('SPON-003 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('sponsorship_campaigns', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('agreement_id');
            $t->foreign(['organization_id', 'agreement_id'])->references(['organization_id', 'id'])->on('sponsorship_agreements')->restrictOnDelete();
            $t->string('title', 160);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $t->enum('status', ['active', 'archived']);
            $t->uuid('version');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'agreement_id', 'id'], 'campaign_agreement_identity');
            $t->index(['organization_id', 'agreement_id', 'status', 'created_at', 'id'], 'campaign_page');
        });
        Schema::create('sponsorship_deliverables', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('agreement_id');
            $t->uuid('campaign_id');
            $t->foreign(['organization_id', 'agreement_id', 'campaign_id'], 'deliverable_campaign')->references(['organization_id', 'agreement_id', 'id'])->on('sponsorship_campaigns')->restrictOnDelete();
            $t->uuid('exposure_id');
            $t->foreign(['organization_id', 'exposure_id'])->references(['organization_id', 'id'])->on('sponsorship_exposures')->restrictOnDelete();
            $t->enum('kind', ['publication', 'video', 'event_presence', 'brand_placement']);
            $t->string('title', 160);
            $t->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('due_at');
            $t->enum('status', ['promised', 'delivered', 'validated', 'cancelled']);
            $t->text('proof_reference')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('validated_at')->nullable();
            $t->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->uuid('validated_agreement_version')->nullable();
            $t->uuid('validated_exposure_version')->nullable();
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->uuid('version');
            $t->timestamps();
            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'campaign_id', 'id'], 'deliverable_campaign_identity');
            $t->index(['organization_id', 'campaign_id', 'status', 'due_at', 'id'], 'deliverable_page');
            $t->index(['organization_id', 'status', 'due_at', 'id'], 'deliverable_dashboard');
        });
        Schema::create('sponsorship_campaign_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('campaign_id');
            $t->foreign(['organization_id', 'campaign_id'])->references(['organization_id', 'id'])->on('sponsorship_campaigns')->restrictOnDelete();
            $t->uuid('deliverable_id')->nullable();
            $t->foreign(['organization_id', 'campaign_id', 'deliverable_id'], 'campaign_event_deliverable')->references(['organization_id', 'campaign_id', 'id'])->on('sponsorship_deliverables')->restrictOnDelete();
            $t->enum('action', ['campaign_created', 'campaign_updated', 'campaign_archived', 'promised', 'updated', 'delivered', 'validated', 'reopened', 'cancelled']);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->uuid('record_version');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('occurred_at');
            $t->text('delivery_snapshot')->nullable();
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'campaign_id', 'occurred_at', 'id'], 'campaign_history_page');
        });
        $this->guards();
        $period = "EXISTS (SELECT 1 FROM sponsorship_campaigns c WHERE c.organization_id=NEW.organization_id AND c.agreement_id=NEW.id AND c.status='active'
            AND (DATE(c.starts_on)<DATE(NEW.starts_on) OR DATE(c.ends_on)>DATE(NEW.ends_on)))";
        $period .= " OR (NEW.status='archived' AND EXISTS (SELECT 1 FROM sponsorship_campaigns c WHERE c.organization_id=NEW.organization_id AND c.agreement_id=NEW.id AND c.status='active'))";
        $sponsorArchive = "NEW.status='archived' AND EXISTS (SELECT 1 FROM sponsorship_campaigns c JOIN sponsorship_agreements a ON a.id=c.agreement_id AND a.organization_id=c.organization_id
            WHERE c.organization_id=NEW.organization_id AND a.sponsor_id=NEW.id AND c.status='active')";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_campaign_agreement_period() RETURNS trigger AS $$ BEGIN
                IF ({$period}) THEN RAISE EXCEPTION 'Active campaigns must fit the agreement period' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER campaign_agreement_period BEFORE UPDATE ON sponsorship_agreements FOR EACH ROW EXECUTE FUNCTION ucg_campaign_agreement_period();
                CREATE FUNCTION ucg_campaign_sponsor_archive() RETURNS trigger AS $$ BEGIN
                PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF ({$sponsorArchive}) THEN RAISE EXCEPTION 'Active campaigns must be archived first' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER campaign_sponsor_archive BEFORE UPDATE ON sponsors FOR EACH ROW EXECUTE FUNCTION ucg_campaign_sponsor_archive()");
        } else {
            DB::unprepared("CREATE TRIGGER campaign_agreement_period BEFORE UPDATE ON sponsorship_agreements WHEN {$period} BEGIN SELECT RAISE(ABORT,'Active campaigns must fit the agreement period'); END");
            DB::unprepared("CREATE TRIGGER campaign_sponsor_archive BEFORE UPDATE ON sponsors WHEN {$sponsorArchive} BEGIN SELECT RAISE(ABORT,'Active campaigns must be archived first'); END");
        }
    }

    private function guards(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        $diff = $pg ? 'IS DISTINCT FROM' : 'IS NOT';
        foreach (self::TABLES as $table) {
            $event = $table === 'sponsorship_campaign_events';
            $identity = ['id', 'organization_id', ...($table === 'sponsorship_campaigns' ? ['agreement_id', 'created_by'] : ['campaign_id']), ...($table === 'sponsorship_deliverables' ? ['agreement_id', 'exposure_id'] : [])];
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} {$diff} OLD.{$c}", $identity));
            $invalid = match ($table) {
                'sponsorship_campaigns' => "NEW.ends_on < NEW.starts_on OR LENGTH(TRIM(NEW.title))=0
                    OR NOT EXISTS (SELECT 1 FROM sponsorship_agreements a WHERE a.id=NEW.agreement_id AND a.organization_id=NEW.organization_id AND NEW.starts_on>=a.starts_on AND NEW.ends_on<=a.ends_on)
                    OR (NEW.status='archived' AND EXISTS (SELECT 1 FROM sponsorship_deliverables d WHERE d.organization_id=NEW.organization_id AND d.campaign_id=NEW.id AND d.status IN ('promised','delivered')))",
                'sponsorship_deliverables' => "LENGTH(TRIM(NEW.title))=0
                    OR NOT EXISTS (SELECT 1 FROM sponsorship_campaigns c WHERE c.id=NEW.campaign_id AND c.organization_id=NEW.organization_id AND c.agreement_id=NEW.agreement_id AND c.status='active')
                    OR NOT EXISTS (SELECT 1 FROM sponsorship_exposures e WHERE e.id=NEW.exposure_id AND e.organization_id=NEW.organization_id AND e.agreement_id=NEW.agreement_id)
                    OR (NEW.status IN ('delivered','validated') AND (NEW.proof_reference IS NULL OR NEW.delivered_at IS NULL OR NEW.delivered_by IS NULL))
                    OR (NEW.status='promised' AND (NEW.proof_reference IS NOT NULL OR NEW.delivered_at IS NOT NULL OR NEW.delivered_by IS NOT NULL))
                    OR (NEW.status='validated' AND (NEW.validated_by IS NULL OR NEW.validated_at IS NULL OR NEW.validated_agreement_version IS NULL OR NEW.validated_exposure_version IS NULL
                        OR NEW.validated_by=NEW.delivered_by OR NEW.validated_by=NEW.prepared_by OR NEW.validated_by=NEW.responsible_user_id))
                    OR (NEW.status!='validated' AND (NEW.validated_by IS NOT NULL OR NEW.validated_at IS NOT NULL OR NEW.validated_agreement_version IS NOT NULL OR NEW.validated_exposure_version IS NOT NULL))",
                default => 'FALSE',
            };
            if (! $event) {
                $immutable .= " OR OLD.status IN ('archived','cancelled')";
            }
            if ($table === 'sponsorship_deliverables') {
                $material = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} {$diff} OLD.{$c}", ['title', 'kind', 'responsible_user_id', 'due_at', 'proof_reference', 'delivered_at', 'delivered_by', 'prepared_by', 'validated_by', 'validated_at', 'validated_agreement_version', 'validated_exposure_version', 'version']));
                $immutable .= " OR (OLD.status='validated' AND NEW.status!='promised') OR (OLD.status IN ('delivered','validated') AND NEW.status=OLD.status AND ({$material}))";
                $delivery = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} {$diff} OLD.{$c}", ['title', 'kind', 'responsible_user_id', 'due_at', 'proof_reference', 'delivered_at', 'delivered_by', 'prepared_by']));
                $immutable .= " OR (OLD.status='delivered' AND NEW.status IN ('validated','cancelled') AND ({$delivery}))";
            }
            if ($pg) {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Campaign history must be retained' USING ERRCODE='23514'; END IF;
                    PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                    IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid campaign state' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' THEN IF (".($event ? 'TRUE' : $immutable).") THEN RAISE EXCEPTION 'Campaign history is immutable' USING ERRCODE='23514'; END IF; END IF;
                    RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT,'Invalid campaign state'); END");
                DB::unprepared("CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN ({$invalid}) OR (".($event ? 'TRUE' : $immutable).") BEGIN SELECT RAISE(ABORT,'Campaign history is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'Campaign history must be retained'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('SPON-003 rollback cannot discard campaign history.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER campaign_agreement_period ON sponsorship_agreements; DROP FUNCTION ucg_campaign_agreement_period(); DROP TRIGGER campaign_sponsor_archive ON sponsors; DROP FUNCTION ucg_campaign_sponsor_archive()');
        } else {
            DB::unprepared('DROP TRIGGER campaign_agreement_period');
            DB::unprepared('DROP TRIGGER campaign_sponsor_archive');
        }
        foreach (self::TABLES as $table) {
            Schema::drop($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION ucg_{$table}_guard()");
            }
        }
    }
};
