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
            throw new RuntimeException('SPON-001 requires SQLite or PostgreSQL integrity guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('sponsors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('industry', 120)->nullable();
            $table->enum('status', ['prospect', 'partner', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'name', 'id'], 'sponsors_name_page');
            $table->index(['organization_id', 'status', 'name', 'id'], 'sponsors_status_page');
        });
        Schema::create('sponsor_contacts', function (Blueprint $table): void {
            $this->child($table);
            $table->string('name', 160);
            $table->string('position', 120)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 32)->nullable();
            $table->enum('status', ['active', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->index(['organization_id', 'sponsor_id', 'status', 'name', 'id'], 'sponsor_contacts_page');
        });
        Schema::create('sponsorship_opportunities', function (Blueprint $table): void {
            $this->child($table);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'contacted', 'negotiating', 'won', 'lost', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->index(['organization_id', 'sponsor_id', 'status', 'title', 'id'], 'sponsor_opportunities_page');
        });
        Schema::create('sponsorship_interactions', function (Blueprint $table): void {
            $this->child($table);
            $table->uuid('contact_id')->nullable();
            $table->uuid('opportunity_id')->nullable();
            $table->foreign(['organization_id', 'sponsor_id', 'contact_id'], 'sponsor_interaction_contact')
                ->references(['organization_id', 'sponsor_id', 'id'])->on('sponsor_contacts')->restrictOnDelete();
            $table->foreign(['organization_id', 'sponsor_id', 'opportunity_id'], 'sponsor_interaction_opportunity')
                ->references(['organization_id', 'sponsor_id', 'id'])->on('sponsorship_opportunities')->restrictOnDelete();
            $table->enum('channel', ['note', 'email', 'phone', 'meeting', 'other']);
            $table->text('summary');
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['organization_id', 'sponsor_id', 'occurred_at', 'id'], 'sponsor_interactions_page');
        });
        $this->guards();
    }

    private function child(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
        $table->uuid('sponsor_id');
        $table->foreign(['organization_id', 'sponsor_id'])->references(['organization_id', 'id'])->on('sponsors')->restrictOnDelete();
        $table->unique(['organization_id', 'id']);
        $table->unique(['organization_id', 'sponsor_id', 'id']);
    }

    private function guards(): void
    {
        $postgres = DB::getDriverName() === 'pgsql';
        $different = $postgres ? 'IS DISTINCT FROM' : 'IS NOT';
        foreach (['sponsors' => 'name', 'sponsor_contacts' => 'name', 'sponsorship_opportunities' => 'title', 'sponsorship_interactions' => 'summary'] as $table => $label) {
            $identityFields = $table === 'sponsors' ? ['id', 'organization_id'] : ['id', 'organization_id', 'sponsor_id'];
            $identity = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$different} OLD.{$field}", $identityFields));
            $parent = $table === 'sponsors' ? 'FALSE' : "NOT EXISTS (SELECT 1 FROM sponsors WHERE id=NEW.sponsor_id AND organization_id=NEW.organization_id AND status!='archived')";
            $invalid = "LENGTH(TRIM(NEW.{$label}))=0 OR ({$parent})";
            $immutable = $table === 'sponsorship_interactions' ? 'TRUE' : "({$identity}) OR OLD.status='archived'";
            if ($postgres) {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Partnership history must be retained' USING ERRCODE='23514'; END IF;
                    PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                    IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid partnership or archived sponsor' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' THEN
                        IF ({$immutable}) THEN RAISE EXCEPTION 'Partnership identity or history is immutable' USING ERRCODE='23514'; END IF;
                    END IF;
                    RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                        FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$invalid}
                    BEGIN SELECT RAISE(ABORT, 'Invalid partnership or archived sponsor'); END");
                DB::unprepared("CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN ({$invalid}) OR ({$immutable})
                    BEGIN SELECT RAISE(ABORT, 'Partnership identity or history is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table}
                    BEGIN SELECT RAISE(ABORT, 'Partnership history must be retained'); END");
            }
        }
    }

    public function down(): void
    {
        $tables = ['sponsorship_interactions', 'sponsorship_opportunities', 'sponsor_contacts', 'sponsors'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('SPON-001 rollback cannot discard partnership history. Use a forward fix.');
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
