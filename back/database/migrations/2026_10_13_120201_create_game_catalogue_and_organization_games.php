<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('TEAM-001 requires PostgreSQL or SQLite.');
        }
        $legacy = $this->legacy();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('game_catalogue_state', function (Blueprint $table): void {
            $table->string('id', 20)->primary();
            $table->uuid('version');
            $table->uuid('initial_revision');
        });
        Schema::create('game_catalogue_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('kind', ['game', 'platform', 'provider', 'region', 'mode', 'role', 'map', 'release']);
            $table->string('key', 64);
            $table->string('name', 120);
            $table->uuid('game_id')->nullable();
            $table->string('scope_key', 36);
            $table->boolean('active');
            $table->boolean('default_enabled')->default(false);
            $table->json('definition')->nullable();
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['scope_key', 'kind', 'key']);
            $table->index(['kind', 'scope_key', 'name', 'id'], 'catalogue_page');
        });
        Schema::table('game_catalogue_entries', function (Blueprint $table): void {
            $table->foreign('game_id')->references('id')->on('game_catalogue_entries')->restrictOnDelete();
        });
        Schema::create('game_catalogue_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('entry_id')->constrained('game_catalogue_entries')->restrictOnDelete();
            $table->uuid('request_id');
            $table->uuid('catalogue_version');
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
            $table->index(['created_at', 'id']);
        });
        Schema::create('organization_games', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('game_id')->constrained('game_catalogue_entries')->restrictOnDelete();
            $table->boolean('enabled');
            $table->json('restrictions');
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'game_id']);
            $table->index(['organization_id', 'enabled', 'id']);
        });
        $revision = (string) Str::uuid();
        DB::table('game_catalogue_state')->insert(['id' => 'catalogue', 'version' => $revision, 'initial_revision' => $revision]);
        $games = [];
        foreach (['platforms' => 'platform', 'providers' => 'provider', 'regions' => 'region', 'games' => 'game'] as $dictionary => $kind) {
            foreach ($legacy[$dictionary] as $key => $entry) {
                $id = (string) Str::uuid();
                if ($kind === 'game') {
                    $games[$key] = $id;
                }
                DB::table('game_catalogue_entries')->insert(['id' => $id, 'kind' => $kind, 'key' => $key, 'name' => $entry['name'],
                    'game_id' => null, 'scope_key' => 'global', 'active' => $entry['active'] ?? true,
                    'default_enabled' => $kind === 'game' && in_array($key, $legacy['enabled_games'], true),
                    'definition' => $kind === 'game' ? json_encode(['platforms' => $entry['platforms'],
                        'regions' => $entry['regions'] ?? array_keys($legacy['regions'])], JSON_THROW_ON_ERROR) : null,
                    'version' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        // Only explicit existing configuration overrides become tenant records.
        foreach ($legacy['tenant_enabled_games'] as $organizationId => $enabled) {
            foreach ($games as $key => $gameId) {
                DB::table('organization_games')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organizationId,
                    'game_id' => $gameId, 'enabled' => in_array($key, $enabled, true), 'restrictions' => '[]',
                    'version' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->guards();
    }

    /** Validate the one-time source before creating any table.
     * @return array<string, mixed>
     */
    private function legacy(): array
    {
        $data = config('game_profiles');
        if (! is_array($data)) {
            throw new RuntimeException('TEAM-001: invalid legacy catalogue.');
        }
        $validKey = static fn (mixed $key, int $length): bool => is_string($key) && strlen($key) <= $length
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) === 1;
        $keys = static function (mixed $value, int $limit, int $length) use ($validKey): bool {
            return is_array($value) && array_is_list($value) && count($value) <= $limit
                && count(array_unique($value, SORT_REGULAR)) === count($value)
                && count(array_filter($value, fn (mixed $key): bool => $validKey($key, $length))) === count($value);
        };
        foreach (['games' => 100, 'platforms' => 32, 'providers' => 32, 'regions' => 32] as $dictionary => $limit) {
            if (! is_array($data[$dictionary] ?? null) || count($data[$dictionary]) > $limit) {
                throw new RuntimeException('TEAM-001: invalid legacy dictionary.');
            }
            foreach ($data[$dictionary] as $key => $entry) {
                if (! $validKey($key, $dictionary === 'games' ? 64 : 32) || ! is_array($entry)
                    || ! is_string($entry['name'] ?? null) || trim($entry['name']) === '' || mb_strlen($entry['name']) > 120
                    || (array_key_exists('active', $entry) && ! is_bool($entry['active']))) {
                    throw new RuntimeException('TEAM-001: invalid legacy reference.');
                }
                if ($dictionary === 'games') {
                    if (! is_array($entry['platforms'] ?? null) || count($entry['platforms']) > 32) {
                        throw new RuntimeException('TEAM-001: invalid platform mapping.');
                    }
                    foreach ($entry['platforms'] as $platform => $providers) {
                        if (! $validKey($platform, 32) || ! array_key_exists($platform, $data['platforms'])
                            || ! $keys($providers, 32, 32) || array_diff($providers, array_keys($data['providers'])) !== []) {
                            throw new RuntimeException('TEAM-001: unknown platform/provider.');
                        }
                    }
                    if (array_key_exists('regions', $entry) && (! $keys($entry['regions'], 32, 32)
                        || array_diff($entry['regions'], array_keys($data['regions'])) !== [])) {
                        throw new RuntimeException('TEAM-001: unknown region.');
                    }
                }
            }
        }
        if (! $keys($data['enabled_games'] ?? null, 100, 64) || array_diff($data['enabled_games'], array_keys($data['games'])) !== []
            || ! is_array($data['tenant_enabled_games'] ?? null)) {
            throw new RuntimeException('TEAM-001: invalid legacy activations.');
        }
        foreach ($data['tenant_enabled_games'] as $organizationId => $enabled) {
            if (! is_string($organizationId) || ! Str::isUuid($organizationId) || strtolower($organizationId) !== $organizationId
                || ! DB::table('organizations')->where('id', $organizationId)->exists() || ! $keys($enabled, 100, 64)
                || array_diff($enabled, array_keys($data['games'])) !== []) {
                throw new RuntimeException('TEAM-001: invalid legacy tenant override.');
            }
        }

        return $data;
    }

    private function guards(): void
    {
        $postgres = DB::getDriverName() === 'pgsql';
        $diff = $postgres ? 'IS DISTINCT FROM' : 'IS NOT';
        $invalidEntry = "(NEW.kind IN ('game','platform','provider','region') AND (NEW.game_id IS NOT NULL OR NEW.scope_key != 'global'))
            OR (NEW.kind IN ('mode','role','map','release') AND (NEW.game_id IS NULL OR NEW.scope_key != CAST(NEW.game_id AS TEXT)
                OR NOT EXISTS (SELECT 1 FROM game_catalogue_entries WHERE id=NEW.game_id AND kind='game')))
            OR (NEW.kind != 'game' AND (NEW.definition IS NOT NULL OR NEW.default_enabled))";
        $invalidGame = "NOT EXISTS (SELECT 1 FROM game_catalogue_entries WHERE id=NEW.game_id AND kind='game')";
        foreach (['game_catalogue_entries' => $invalidEntry, 'organization_games' => $invalidGame] as $table => $invalid) {
            $identity = $table === 'game_catalogue_entries' ? ['id', 'kind', 'key', 'game_id', 'scope_key', 'default_enabled'] : ['id', 'organization_id', 'game_id'];
            $frozen = implode(' OR ', array_map(fn (string $field): string => "NEW.\"{$field}\" {$diff} OLD.\"{$field}\"", $identity));
            if ($postgres) {
                $revisionUpdate = $table === 'game_catalogue_entries'
                    ? "UPDATE game_catalogue_state SET version=gen_random_uuid() WHERE id='catalogue';" : '';
                DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Catalogue history must be retained' USING ERRCODE='23514'; END IF;
                    IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid catalogue scope' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' AND ({$frozen}) THEN RAISE EXCEPTION 'Catalogue identity is immutable' USING ERRCODE='23514'; END IF;
                    {$revisionUpdate} RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid catalogue scope'); END");
                DB::unprepared("CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN ({$invalid}) OR ({$frozen}) BEGIN SELECT RAISE(ABORT, 'Catalogue identity is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Catalogue history must be retained'); END");
                if ($table === 'game_catalogue_entries') {
                    $uuid = "lower(hex(randomblob(4))) || '-' || lower(hex(randomblob(2))) || '-4' || substr(lower(hex(randomblob(2))),2,3)
                        || '-8' || substr(lower(hex(randomblob(2))),2,3) || '-' || lower(hex(randomblob(6)))";
                    foreach (['INSERT', 'UPDATE'] as $operation) {
                        DB::unprepared("CREATE TRIGGER catalogue_revision_{$operation} AFTER {$operation} ON game_catalogue_entries
                            BEGIN UPDATE game_catalogue_state SET version={$uuid} WHERE id='catalogue'; END");
                    }
                }
            }
        }
        $invalidState = "NEW.id {$diff} OLD.id OR NEW.initial_revision {$diff} OLD.initial_revision
            OR (OLD.version != OLD.initial_revision AND NEW.version = OLD.initial_revision)";
        if ($postgres) {
            DB::unprepared("CREATE FUNCTION ucg_game_catalogue_state_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Catalogue state must be retained' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' THEN
                    IF NEW.id != 'catalogue' OR NEW.version != NEW.initial_revision THEN RAISE EXCEPTION 'Invalid catalogue baseline' USING ERRCODE='23514'; END IF;
                    RETURN NEW;
                END IF;
                IF {$invalidState} THEN RAISE EXCEPTION 'Catalogue baseline is immutable' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER game_catalogue_state_guard BEFORE INSERT OR UPDATE OR DELETE ON game_catalogue_state FOR EACH ROW EXECUTE FUNCTION ucg_game_catalogue_state_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER catalogue_state_insert BEFORE INSERT ON game_catalogue_state
                WHEN NEW.id != 'catalogue' OR NEW.version != NEW.initial_revision BEGIN SELECT RAISE(ABORT, 'Invalid catalogue baseline'); END");
            DB::unprepared("CREATE TRIGGER catalogue_state_update BEFORE UPDATE ON game_catalogue_state WHEN {$invalidState}
                BEGIN SELECT RAISE(ABORT, 'Catalogue baseline is immutable'); END");
            DB::unprepared("CREATE TRIGGER catalogue_state_delete BEFORE DELETE ON game_catalogue_state BEGIN SELECT RAISE(ABORT, 'Catalogue state must be retained'); END");
        }
        if ($postgres) {
            DB::unprepared("CREATE FUNCTION ucg_game_catalogue_events_guard() RETURNS trigger AS $$ BEGIN
                RAISE EXCEPTION 'Catalogue audit is append-only' USING ERRCODE='23514'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER game_catalogue_events_guard BEFORE UPDATE OR DELETE ON game_catalogue_events FOR EACH ROW EXECUTE FUNCTION ucg_game_catalogue_events_guard()");
        } else {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER game_catalogue_events_{$operation} BEFORE {$operation} ON game_catalogue_events BEGIN SELECT RAISE(ABORT, 'Catalogue audit is append-only'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        $state = DB::table('game_catalogue_state')->where('id', 'catalogue')->lockForUpdate()->first();
        if ($state === null || $state->version !== $state->initial_revision || DB::table('game_catalogue_events')->exists()
            || DB::table('organization_games')->exists()) {
            throw new RuntimeException('TEAM-001 rollback cannot discard catalogue changes or tenant settings. Use a forward fix.');
        }
        Schema::drop('organization_games');
        Schema::drop('game_catalogue_events');
        Schema::drop('game_catalogue_entries');
        Schema::drop('game_catalogue_state');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_organization_games_guard(); DROP FUNCTION ucg_game_catalogue_events_guard(); DROP FUNCTION ucg_game_catalogue_entries_guard(); DROP FUNCTION ucg_game_catalogue_state_guard()');
        }
    }
};
