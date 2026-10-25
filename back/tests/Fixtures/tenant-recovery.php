<?php

use App\Modules\People\Application\Data\PersonData;
use App\Modules\People\Application\Services\PersonService;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabasePortabilityRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function tenantRecoveryInput(): array
{
    $contents = stream_get_contents(STDIN, 65537);
    if (! is_string($contents) || strlen($contents) > 65536) {
        throw new RuntimeException('TENANT_RECOVERY_INPUT');
    }
    $input = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($input)) {
        throw new RuntimeException('TENANT_RECOVERY_INPUT');
    }

    return $input;
}

function tenantRecoveryHash(mixed $value): string
{
    return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
}

/** @param array<string, mixed> $input
 * @return array{confirmation:string,source:array{organization_id:string,person_id:string,snapshot_version:string,fields:array{pseudonym:string,first_name:string,contact_email:string},artifact:array{id:string,bytes:int,contents:string,sha256:string,ledger_hash:string}}}
 */
function tenantRecoveryRequest(Application $app, array $input): array
{
    /** @var array{confirmation:string,source:array{organization_id:string,person_id:string,snapshot_version:string,fields:array{pseudonym:string,first_name:string,contact_email:string},artifact:array{id:string,bytes:int,contents:string,sha256:string,ledger_hash:string}}} $validated */
    $validated = $app->make(Factory::class)->make($input, [
        'confirmation' => ['required', 'uuid'],
        'source' => ['required', 'array:organization_id,person_id,snapshot_version,fields,artifact'],
        'source.organization_id' => ['required', 'uuid'], 'source.person_id' => ['required', 'uuid'],
        'source.snapshot_version' => ['required', 'uuid'],
        'source.fields' => ['required', 'array:pseudonym,first_name,contact_email'],
        'source.fields.pseudonym' => ['required', 'string', 'max:80'],
        'source.fields.first_name' => ['required', 'string', 'max:120'],
        'source.fields.contact_email' => ['required', 'email:rfc', 'max:254'],
        'source.artifact' => ['required', 'array:id,bytes,contents,sha256,ledger_hash'],
        'source.artifact.id' => ['required', 'uuid'], 'source.artifact.bytes' => ['required', 'integer', 'min:1', 'max:8192'],
        'source.artifact.contents' => ['required', 'string', 'max:12000'],
        'source.artifact.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        'source.artifact.ledger_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
    ])->validate();

    return $validated;
}

if (getenv('UCG_RECOVERY_FIXTURE') !== 'v1' || getenv('UCG_BROWSER_ENGINE') !== 'pgsql'
    || ! in_array($argv[1] ?? '', ['prepare', 'export-alpha', 'export-beta', 'inspect', 'damage', 'late-change', 'plan', 'apply', 'apply-fail', 'verify'], true)) {
    throw new RuntimeException('An isolated tenant recovery fixture is required.');
}
try {
    /** @var Application $app */
    $app = require __DIR__.'/browser-application.php';
    $app->make(Kernel::class)->bootstrap();
    $db = $app->make(DatabaseManager::class)->connection();
    $app->make(DatabasePortabilityRepository::class)->assertCatalog();
    $operation = $argv[1];
    $alpha = Organization::query()->where('slug', 'recovery-alpha')->firstOrFail();
    $beta = Organization::query()->where('slug', 'recovery-beta')->firstOrFail();
    $files = $app->make(TenantArtifacts::class);
    $resolver = $app->make(TenantResolver::class);
    $alphaContext = $resolver->resolve($alpha->id, '', $alpha->owner_user_id, (string) Str::uuid());

    if ($operation === 'prepare') {
        foreach ([$alpha, $beta] as $organization) {
            $role = OrganizationRole::factory()->create(['organization_id' => $organization->id,
                'permissions' => ['sports.read', 'sports.write', 'administrative.read', 'administrative.write'], 'privileged' => true]);
            OrganizationRoleAssignment::factory()->create(['organization_id' => $organization->id,
                'user_id' => $organization->owner_user_id, 'membership_id' => null, 'role_id' => $role->id, 'scope_id' => $organization->id]);
        }
        echo json_encode(['prepared' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($operation === 'inspect') {
        $result = ['all' => [], 'beta' => [], 'global' => []];
        foreach ($db->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $entry) {
            $table = $entry->tablename;
            if (! is_string($table) || preg_match('/^[a-z_]+$/D', $table) !== 1) {
                throw new RuntimeException('TENANT_RECOVERY_CATALOG');
            }
            foreach (['all', array_key_exists($table, DatabasePortabilityRepository::TABLES) ? 'beta' : 'global'] as $scope) {
                $where = $scope === 'beta' ? ' WHERE '.($table === 'organizations' ? 'id' : 'organization_id').' = ?' : '';
                $hash = hash_init('sha256');
                $count = 0;
                foreach ($db->cursor('SELECT row_to_json(t)::text AS row FROM "'.$table.'" t'.$where.' ORDER BY row_to_json(t)::text', $scope === 'beta' ? [$beta->id] : []) as $row) {
                    hash_update($hash, $row->row."\n");
                    $count++;
                }
                $result[$scope][$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
            }
        }
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit;
    }

    if (in_array($operation, ['export-alpha', 'export-beta'], true)) {
        $organization = $operation === 'export-alpha' ? $alpha : $beta;
        $context = $resolver->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $person = Person::query()->where('organization_id', $organization->id)->sole();
        $ledger = $db->table('tenant_artifacts')->where('organization_id', $organization->id)->sole();
        $contents = $files->read($context, $ledger->id);
        echo json_encode(['organization_id' => $organization->id, 'person_id' => $person->id,
            'snapshot_version' => $person->version,
            'fields' => ['pseudonym' => $person->pseudonym, 'first_name' => $person->first_name, 'contact_email' => $person->contact_email],
            'artifact' => ['id' => $ledger->id, 'bytes' => strlen($contents), 'contents' => base64_encode($contents),
                'sha256' => hash('sha256', $contents), 'ledger_hash' => tenantRecoveryHash($ledger)]], JSON_THROW_ON_ERROR);
        exit;
    }

    if (in_array($operation, ['damage', 'late-change'], true)) {
        $person = Person::query()->where('organization_id', $alpha->id)->sole();
        $app->make(PersonService::class)->update($alphaContext, $person->id,
            new PersonData(['pseudonym' => $operation === 'damage' ? 'Damaged Alpha' : 'Alpha changed after plan', 'first_name' => 'Changed Alpha'], $person->version));
        if ($operation === 'damage') {
            $context = $resolver->resolve($beta->id, '', $beta->owner_user_id, (string) Str::uuid());
            $person = Person::query()->where('organization_id', $beta->id)->sole();
            $app->make(PersonService::class)->update($context, $person->id,
                new PersonData(['pseudonym' => 'Beta newer than backup', 'contact_email' => 'beta-new@example.test'], $person->version));
            Person::factory()->create(['organization_id' => $beta->id, 'pseudonym' => 'Beta created after backup']);
            $files->store($context, 'newer.txt', 'Private Beta newer');
        }
        echo json_encode(['advanced' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($operation === 'verify') {
        $person = Person::query()->where('organization_id', $alpha->id)->sole();
        $ledger = $db->table('tenant_artifacts')->where('organization_id', $alpha->id)->sole();
        if ($person->pseudonym !== 'Recovery alpha' || $person->first_name !== 'Fictitious alpha'
            || $person->contact_email !== 'alpha@example.test' || $files->read($alphaContext, $ledger->id) !== 'Private recovery alpha'
            || Person::query()->where('organization_id', $beta->id)->count() !== 2
            || ! Person::query()->where('organization_id', $beta->id)->where('pseudonym', 'Beta newer than backup')->exists()) {
            throw new RuntimeException('TENANT_RECOVERY_VERIFY');
        }
        echo json_encode(['alphaRecovered' => true, 'betaNewRowsRetained' => true,
            'alphaAuditRows' => $db->table('organization_audit_events')->where('organization_id', $alpha->id)->count(),
            'alphaVersion' => $person->version], JSON_THROW_ON_ERROR);
        exit;
    }

    $input = tenantRecoveryInput();
    if ($operation === 'plan') {
        $request = tenantRecoveryRequest($app, $input);
    } else {
        $plan = $input['plan'] ?? null;
        $signature = $input['signature'] ?? null;
        if (! is_array($plan) || ! is_string($signature)
            || ! hash_equals(hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), (string) getenv('UCG_BROWSER_TOKEN')), $signature)
            || ! is_array($plan['request'] ?? null)) {
            throw new RuntimeException('TENANT_RECOVERY_SIGNATURE');
        }
        $request = tenantRecoveryRequest($app, $plan['request']);
    }
    $source = $request['source'];
    if ($request['confirmation'] !== $alpha->id || $source['organization_id'] !== $alpha->id) {
        throw new RuntimeException('TENANT_RECOVERY_SCOPE');
    }
    $person = Person::query()->where('organization_id', $alpha->id)->whereKey($source['person_id'])->first();
    $ledger = $db->table('tenant_artifacts')->where('organization_id', $alpha->id)->where('id', $source['artifact']['id'])->first();
    if ($person === null || $ledger === null || tenantRecoveryHash($ledger) !== $source['artifact']['ledger_hash']) {
        throw new RuntimeException('TENANT_RECOVERY_SCOPE');
    }
    $contents = base64_decode($source['artifact']['contents'], true);
    if (! is_string($contents) || strlen($contents) !== $source['artifact']['bytes'] || hash('sha256', $contents) !== $source['artifact']['sha256']) {
        throw new RuntimeException('TENANT_RECOVERY_CONTENT');
    }
    $parent = realpath(storage_path('app/private/tenants/'.$alpha->id.'/artifacts'));
    $expectedParent = realpath(storage_path('app/private')).DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.$alpha->id.DIRECTORY_SEPARATOR.'artifacts';
    if ($parent === false || $parent !== $expectedParent) {
        throw new RuntimeException('TENANT_RECOVERY_PATH');
    }
    $path = $parent.DIRECTORY_SEPARATOR.$source['artifact']['id'];
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('TENANT_RECOVERY_FILE_EXISTS');
    }
    if ($operation === 'plan') {
        $plan = ['request' => $request, 'expected_version' => $person->version,
            'expected_person_hash' => tenantRecoveryHash($person->getRawOriginal()), 'expected_ledger_hash' => tenantRecoveryHash($ledger)];
        echo json_encode(['plan' => $plan, 'signature' => hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), (string) getenv('UCG_BROWSER_TOKEN'))], JSON_THROW_ON_ERROR);
        exit;
    }

    $installed = false;
    try {
        $db->transaction(function () use ($app, $db, $alphaContext, $source, $plan, $person, $contents, $path, $operation, &$installed): void {
            $app->make(TenantBusinessGuard::class)->lock($alphaContext);
            $locked = Person::query()->where('organization_id', $alphaContext->organizationId)->whereKey($person->id)->lockForUpdate()->firstOrFail();
            $lockedLedger = $db->table('tenant_artifacts')->where('organization_id', $alphaContext->organizationId)->where('id', $source['artifact']['id'])->lockForUpdate()->first();
            if ($locked->version !== ($plan['expected_version'] ?? null) || tenantRecoveryHash($locked->getRawOriginal()) !== ($plan['expected_person_hash'] ?? null)
                || tenantRecoveryHash($lockedLedger) !== ($plan['expected_ledger_hash'] ?? null)) {
                throw new RuntimeException('TENANT_RECOVERY_STALE');
            }
            $app->make(PersonService::class)->update($alphaContext, $locked->id, new PersonData($source['fields'], $locked->version));
            $handle = fopen($path, 'xb');
            if ($handle === false) {
                throw new RuntimeException('TENANT_RECOVERY_FILE_EXISTS');
            }
            $installed = true;
            try {
                if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                    throw new RuntimeException('TENANT_RECOVERY_WRITE');
                }
            } finally {
                fclose($handle);
            }
            if ($operation === 'apply-fail') {
                throw new RuntimeException('TENANT_RECOVERY_SIMULATED_FAILURE');
            }
        });
    } catch (Throwable $exception) {
        if ($installed && ! unlink($path)) {
            throw new RuntimeException('TENANT_RECOVERY_CLEANUP', previous: $exception);
        }
        throw $exception;
    }
    echo json_encode(['applied' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $reason = $exception instanceof AuthorizationException ? 'TENANT_RECOVERY_FORBIDDEN'
        : (preg_match('/^TENANT_RECOVERY_[A-Z_]+$/D', $exception->getMessage()) === 1 ? $exception->getMessage() : 'TENANT_RECOVERY_FIXTURE_FAILED');
    echo json_encode(['refused' => $reason], JSON_THROW_ON_ERROR);
    exit(2);
}
