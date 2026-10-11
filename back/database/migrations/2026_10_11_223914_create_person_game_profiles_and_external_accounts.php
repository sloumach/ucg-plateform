<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('HUM-002 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('person_game_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('person_id');
            $table->string('game_key', 64);
            $table->string('platform_key', 32);
            $table->string('region_key', 32);
            $table->string('rank', 80)->nullable();
            $table->string('game_role', 80)->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->enum('status', ['active', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->foreign(['organization_id', 'person_id'], 'profiles_person_fk')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->unique(['organization_id', 'id', 'game_key', 'platform_key', 'region_key'], 'profiles_tenant_context');
            $table->unique(['organization_id', 'person_id', 'game_key', 'platform_key', 'region_key'], 'profiles_person_context');
            $table->index(['organization_id', 'person_id', 'id'], 'profiles_person_page');
        });
        Schema::create('person_external_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('game_profile_id');
            $table->string('game_key', 64);
            $table->string('platform_key', 32);
            $table->string('region_key', 32);
            $table->string('provider_key', 32);
            $table->string('external_id', 191)->nullable();
            $table->string('display_name', 120)->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->enum('status', ['active', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->foreign(['organization_id', 'game_profile_id', 'game_key', 'platform_key', 'region_key'], 'accounts_profile_context_fk')
                ->references(['organization_id', 'id', 'game_key', 'platform_key', 'region_key'])->on('person_game_profiles')->restrictOnDelete();
            $table->unique(['organization_id', 'game_key', 'platform_key', 'region_key', 'provider_key', 'external_id'], 'accounts_external_context');
            $table->index(['organization_id', 'game_profile_id', 'id'], 'accounts_profile_page');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE person_external_accounts ADD CONSTRAINT accounts_identity_required CHECK (external_id IS NOT NULL OR (display_name IS NOT NULL AND btrim(display_name) <> ''))");
            DB::statement("ALTER TABLE person_external_accounts ADD CONSTRAINT accounts_external_format CHECK (external_id IS NULL OR (external_id <> '' AND external_id !~ '[^!-~]'))");
        } else {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER accounts_identity_{$operation} BEFORE {$operation} ON person_external_accounts
                    WHEN (NEW.external_id IS NULL AND (NEW.display_name IS NULL OR trim(NEW.display_name) = ''))
                        OR (NEW.external_id IS NOT NULL AND (length(NEW.external_id) < 1 OR length(NEW.external_id) > 191 OR NEW.external_id GLOB '*[^!-~]*'))
                    BEGIN SELECT RAISE(ABORT, 'Invalid external identity'); END");
            }
        }
        $this->protect('person_game_profiles', ['id', 'organization_id', 'person_id', 'game_key', 'platform_key', 'region_key']);
        $this->protect('person_external_accounts', ['id', 'organization_id', 'game_profile_id', 'game_key', 'platform_key', 'region_key', 'provider_key'], true);
    }

    /** @param literal-string $table
     * @param  list<literal-string>  $identity
     */
    private function protect(string $table, array $identity, bool $external = false): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $conditions = [];
            foreach ($identity as $column) {
                $conditions[] = "NEW.{$column} IS DISTINCT FROM OLD.{$column}";
            }
            $condition = implode(' OR ', $conditions);
            if ($external) {
                $condition .= ' OR (OLD.external_id IS NOT NULL AND NEW.external_id IS DISTINCT FROM OLD.external_id)';
            }
            DB::unprepared("CREATE FUNCTION ucg_{$table}_protect() RETURNS trigger AS $$ BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Profile history must be retained' USING ERRCODE='23514'; END IF;
                IF {$condition} THEN RAISE EXCEPTION 'Profile identity is immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER {$table}_protect BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_protect()");
        } else {
            $conditions = [];
            foreach ($identity as $column) {
                $conditions[] = "NEW.{$column} IS NOT OLD.{$column}";
            }
            $condition = implode(' OR ', $conditions);
            if ($external) {
                $condition .= ' OR (OLD.external_id IS NOT NULL AND NEW.external_id IS NOT OLD.external_id)';
            }
            DB::unprepared("CREATE TRIGGER {$table}_identity BEFORE UPDATE ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Profile identity is immutable'); END");
            DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table}
                BEGIN SELECT RAISE(ABORT, 'Profile history must be retained'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        foreach (['person_external_accounts', 'person_game_profiles'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('HUM-002 rollback cannot discard game profiles or external accounts. Use a forward fix.');
            }
        }
        foreach (['person_external_accounts', 'person_game_profiles'] as $table) {
            Schema::dropIfExists($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS ucg_{$table}_protect()");
            }
        }
    }
};
