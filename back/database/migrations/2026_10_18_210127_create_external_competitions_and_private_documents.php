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
            throw new RuntimeException('External competitions require SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('external_competitions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->string('reference', 64);
            $t->uuid('game_id');
            $t->string('game_key', 100);
            $t->string('game_name', 200);
            $t->text('name');
            $t->text('organizer');
            $t->string('region', 80);
            $t->string('season', 100);
            $t->text('website_url')->nullable();
            $t->text('contacts');
            $t->string('status', 16)->default('active');
            $t->uuid('version');
            $t->unsignedBigInteger('created_by');
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign('game_id')->references('id')->on('game_catalogue_entries')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'id'], 'ec_tenant_id');
            $t->unique(['organization_id', 'reference'], 'ec_tenant_reference');
            $t->index(['organization_id', 'status', 'created_at', 'id'], 'ec_tenant_status_page');
            $t->index(['organization_id', 'game_id', 'created_at', 'id'], 'ec_tenant_game_page');
        });
        Schema::create('external_competition_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('competition_id');
            $t->unsignedSmallInteger('number');
            $t->uuid('base_version')->nullable();
            $t->uuid('version');
            $t->text('snapshot');
            $t->text('changed_fields');
            $t->text('reason');
            $t->unsignedBigInteger('actor_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign(['organization_id', 'competition_id'], 'ecr_parent')->references(['organization_id', 'id'])->on('external_competitions')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'competition_id', 'number'], 'ecr_tenant_number');
            $t->unique(['organization_id', 'competition_id', 'version'], 'ecr_tenant_version');
        });
        Schema::create('external_competition_documents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('competition_id');
            $t->uuid('document_id');
            $t->unsignedSmallInteger('number');
            $t->string('kind', 16);
            $t->text('title');
            $t->text('body');
            $t->text('source_url')->nullable();
            $t->text('reason');
            $t->unsignedBigInteger('actor_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign(['organization_id', 'competition_id'], 'ecd_parent')->references(['organization_id', 'id'])->on('external_competitions')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'competition_id', 'document_id', 'number'], 'ecd_tenant_document_number');
            $t->index(['organization_id', 'competition_id', 'kind', 'number'], 'ecd_tenant_kind');
        });
        DB::statement("CREATE UNIQUE INDEX ecd_single_rules ON external_competition_documents (organization_id,competition_id,kind) WHERE kind='rules' AND number=1");
        $insert = "NEW.status<>'active' OR NEW.reference<>UPPER(NEW.reference) OR LENGTH(NEW.reference)<2 OR NOT EXISTS(SELECT 1 FROM game_catalogue_entries g WHERE g.id=NEW.game_id AND g.kind='game')";
        $update = "OLD.status='archived' OR NEW.id<>OLD.id OR NEW.organization_id<>OLD.organization_id OR NEW.reference<>OLD.reference OR NEW.game_id<>OLD.game_id OR NEW.game_key<>OLD.game_key OR NEW.game_name<>OLD.game_name OR NEW.created_by<>OLD.created_by OR NEW.created_at<>OLD.created_at OR NEW.status NOT IN ('active','archived') OR NEW.version=OLD.version OR NOT EXISTS(SELECT 1 FROM external_competition_revisions r WHERE r.organization_id=NEW.organization_id AND r.competition_id=NEW.id AND r.base_version=OLD.version AND r.version=NEW.version)";
        $revision = "NEW.number<1 OR NEW.number>1000 OR NEW.number<>(SELECT COALESCE(MAX(r.number),0)+1 FROM external_competition_revisions r WHERE r.organization_id=NEW.organization_id AND r.competition_id=NEW.competition_id)
            OR NOT EXISTS(SELECT 1 FROM external_competitions p WHERE p.organization_id=NEW.organization_id AND p.id=NEW.competition_id AND p.status='active' AND ((NEW.number=1 AND NEW.base_version IS NULL AND p.version=NEW.version) OR (NEW.number>1 AND p.version=NEW.base_version AND EXISTS(SELECT 1 FROM external_competition_revisions r WHERE r.organization_id=NEW.organization_id AND r.competition_id=NEW.competition_id AND r.version=NEW.base_version AND r.number=NEW.number-1))))";
        $document = "NEW.number<1 OR NEW.number>100 OR NEW.kind NOT IN ('rules','note') OR NOT EXISTS(SELECT 1 FROM external_competitions p WHERE p.organization_id=NEW.organization_id AND p.id=NEW.competition_id AND p.status='active')
            OR NEW.number<>(SELECT COALESCE(MAX(d.number),0)+1 FROM external_competition_documents d WHERE d.organization_id=NEW.organization_id AND d.competition_id=NEW.competition_id AND d.document_id=NEW.document_id)
            OR (NEW.number=1 AND (NEW.id<>NEW.document_id OR (SELECT COUNT(*) FROM external_competition_documents d WHERE d.organization_id=NEW.organization_id AND d.competition_id=NEW.competition_id AND d.number=1)>=100))
            OR (NEW.number>1 AND NOT EXISTS(SELECT 1 FROM external_competition_documents d WHERE d.organization_id=NEW.organization_id AND d.competition_id=NEW.competition_id AND d.document_id=NEW.document_id AND d.number=NEW.number-1 AND d.kind=NEW.kind))";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER ec_insert BEFORE INSERT ON external_competitions WHEN {$insert} BEGIN SELECT RAISE(ABORT,'Invalid competition identity'); END");
            DB::unprepared("CREATE TRIGGER ec_update BEFORE UPDATE ON external_competitions WHEN {$update} BEGIN SELECT RAISE(ABORT,'Competition revision required'); END");
            DB::unprepared("CREATE TRIGGER ec_delete BEFORE DELETE ON external_competitions BEGIN SELECT RAISE(ABORT,'Retain competition'); END");
            foreach (['external_competition_revisions' => ['ecr', $revision], 'external_competition_documents' => ['ecd', $document]] as $table => [$prefix,$condition]) {
                DB::unprepared("CREATE TRIGGER {$prefix}_insert BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid competition revision'); END");
                DB::unprepared("CREATE TRIGGER {$prefix}_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT,'Immutable competition revision'); END");
                DB::unprepared("CREATE TRIGGER {$prefix}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT,'Immutable competition revision'); END");
            }
        } else {
            DB::unprepared("CREATE FUNCTION ec_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain competition'; END IF;
                IF TG_OP='INSERT' THEN IF {$insert} THEN RAISE EXCEPTION 'Invalid competition identity'; END IF;
                ELSE IF {$update} THEN RAISE EXCEPTION 'Competition revision required'; END IF; END IF;
                RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER ec_guard BEFORE INSERT OR UPDATE OR DELETE ON external_competitions FOR EACH ROW EXECUTE FUNCTION ec_guard()');
            foreach (['external_competition_revisions' => ['ecr', $revision], 'external_competition_documents' => ['ecd', $document]] as $table => [$prefix,$condition]) {
                DB::unprepared("CREATE FUNCTION {$prefix}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable competition revision'; END IF;
                    PERFORM 1 FROM external_competitions WHERE organization_id=NEW.organization_id AND id=NEW.competition_id FOR UPDATE;
                    IF {$condition} THEN RAISE EXCEPTION 'Invalid competition revision'; END IF; RETURN NEW; END \$\$");
                DB::unprepared("CREATE TRIGGER {$prefix}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$prefix}_guard()");
            }
        }
    }

    public function down(): void
    {
        foreach (['external_competition_documents', 'external_competition_revisions', 'external_competitions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('External competition history must be retained.');
            }
        }
        Schema::dropIfExists('external_competition_documents');
        Schema::dropIfExists('external_competition_revisions');
        Schema::dropIfExists('external_competitions');
        if (DB::getDriverName() === 'pgsql') {
            foreach (['ec', 'ecr', 'ecd'] as $prefix) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$prefix}_guard()");
            }
        }
    }
};
