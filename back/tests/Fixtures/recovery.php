<?php

use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

// Only the existing ownership-checked PostgreSQL harness can boot this fixture.
if (getenv('UCG_RECOVERY_FIXTURE') !== 'v1' || getenv('UCG_BROWSER_ENGINE') !== 'pgsql'
    || ! in_array($argv[1] ?? '', ['empty', 'seed', 'inspect', 'verify', 'advance'], true)) {
    throw new RuntimeException('An isolated recovery fixture is required.');
}
try {
    /** @var Application $application */
    $application = require __DIR__.'/browser-application.php';
    $kernel = $application->make(Kernel::class);
    $kernel->bootstrap();
    $database = $application->make(DatabaseManager::class)->connection();
    $operation = $argv[1];
    $objects = (int) $database->scalar("SELECT COUNT(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'");
    if (in_array($operation, ['empty', 'seed'], true) && $objects !== 0) {
        throw new RuntimeException('Recovery requires an empty owned PostgreSQL target.');
    }
    if ($operation === 'empty') {
        echo json_encode(['empty' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'seed') {
        if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Recovery fixture migration failed.');
        }
        foreach (['alpha', 'beta'] as $label) {
            $organization = Organization::factory()->withMfaOwner()->create(['slug' => 'recovery-'.$label]);
            Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'Recovery '.$label,
                'first_name' => 'Fictitious '.$label, 'contact_email' => $label.'@example.test']);
            $context = $application->make(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
            $application->make(TenantArtifacts::class)->store($context, 'private.txt', 'Private recovery '.$label);
        }
        echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'advance') {
        $person = Person::query()->where('pseudonym', 'Recovery alpha')->firstOrFail();
        $person->pseudonym = 'Changed after backup';
        $person->save();
        echo json_encode(['advanced' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'inspect') {
        // Stable row digests cover every public table, including empty module tables.
        $tables = $database->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
        $fingerprints = [];
        foreach ($tables as $table) {
            $name = $table->tablename;
            if (! is_string($name) || preg_match('/^[a-z_]+$/D', $name) !== 1) {
                throw new RuntimeException('Unexpected recovery table name.');
            }
            $hash = hash_init('sha256');
            $count = 0;
            foreach ($database->cursor('SELECT row_to_json(t)::text AS row FROM "'.$name.'" t ORDER BY row_to_json(t)::text') as $row) {
                hash_update($hash, $row->row."\n");
                $count++;
            }
            $fingerprints[$name] = ['rows' => $count, 'sha256' => hash_final($hash)];
        }
        $sequences = $database->select("SELECT sequencename, start_value, increment_by, max_value, min_value, cache_size, cycle, last_value FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename");
        // pg_dump may rewrite equivalent CHECK array casts; compare their inventory
        // and exercise CHECK/FK behavior below instead of comparing deparser spelling.
        $constraints = $database->select("SELECT conrelid::regclass::text AS relation, conname, contype, conkey, convalidated, condeferrable, condeferred, CASE WHEN contype='c' THEN NULL ELSE pg_get_constraintdef(oid) END AS definition FROM pg_constraint WHERE connamespace='public'::regnamespace ORDER BY relation, conname");
        $triggers = $database->select("SELECT tgrelid::regclass::text AS relation, tgname, tgenabled, pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace='public'::regnamespace) ORDER BY relation, tgname");
        $functions = $database->select("SELECT proname, pg_get_function_identity_arguments(oid) AS arguments, pg_get_functiondef(oid) AS definition FROM pg_proc WHERE pronamespace='public'::regnamespace ORDER BY proname, arguments");
        echo json_encode(['tables' => $fingerprints, 'sequences' => $sequences, 'constraints' => $constraints, 'triggers' => $triggers, 'functions' => $functions], JSON_THROW_ON_ERROR);
        exit;
    }

    $artifacts = $application->make(TenantArtifacts::class);
    $contexts = [];
    $ids = [];
    foreach (['alpha', 'beta'] as $label) {
        $organization = Organization::query()->where('slug', 'recovery-'.$label)->firstOrFail();
        $person = Person::query()->where('organization_id', $organization->id)->firstOrFail();
        if ($person->pseudonym !== 'Recovery '.$label || $person->first_name !== 'Fictitious '.$label
            || $person->contact_email !== $label.'@example.test') {
            throw new RuntimeException('Restored point-in-time data or decryption failed.');
        }
        $context = $application->make(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $id = $database->table('tenant_artifacts')->where('organization_id', $organization->id)->value('id');
        if (! is_string($id) || $artifacts->read($context, $id) !== 'Private recovery '.$label) {
            throw new RuntimeException('Restored private file or ledger mismatch.');
        }
        $contexts[$label] = $context;
        $ids[$label] = $id;
    }
    try {
        $artifacts->read($contexts['alpha'], $ids['beta']);
        throw new RuntimeException('Cross-tenant recovery file access was accepted.');
    } catch (ModelNotFoundException) {
        // The restored ledger must enforce the same non-disclosing tenant boundary.
    }
    try {
        $database->transaction(fn (): int => $database->table('people')->delete());
        throw new RuntimeException('Restored history deletion guard is missing.');
    } catch (QueryException $exception) {
        if ($exception->getCode() !== '23514') {
            throw $exception;
        }
    }
    foreach (['check' => '23514', 'foreign_key' => '23503'] as $probe => $expectedCode) {
        try {
            $database->transaction(function () use ($database, $probe, $contexts): void {
                if ($probe === 'check') {
                    $database->table('people')->where('organization_id', $contexts['alpha']->organizationId)->update(['status' => 'invalid-recovery-status']);
                } else {
                    $database->table('people')->insert(['id' => (string) Str::uuid(), 'organization_id' => (string) Str::uuid(),
                        'pseudonym' => 'Never persisted', 'language' => 'fr', 'timezone' => 'UTC', 'status' => 'candidate', 'version' => (string) Str::uuid()]);
                }
            });
            throw new RuntimeException('Restored constraint probe was accepted.');
        } catch (QueryException $exception) {
            if ($exception->getCode() !== $expectedCode) {
                throw $exception;
            }
        }
    }
    echo json_encode(['decryption' => true, 'private_files' => 2, 'tenant_isolation' => true, 'history_guard' => true, 'check_and_fk' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // Laravel's global console handler need not exit nonzero outside Artisan.
    fwrite(STDERR, 'Recovery fixture refused or failed ('.$exception::class.').'.PHP_EOL);
    exit(2);
}
