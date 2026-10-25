<?php

namespace Tests\Fixtures;

use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabasePortabilityRepository;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use RuntimeException;

/** A restartable local recovery experiment; deliberately absent from production bindings. */
final readonly class RecoveryGraphJournal
{
    public function __construct(private Application $app, private Connection $db,
        private RecoveryGraphRepository $graph, private DatabasePortabilityRepository $catalog,
        private string $root, private string $token, private string $sourceKey) {}

    /** @param array<string,mixed> $packet
     * @return array<string,mixed>
     */
    public function authenticate(array $packet): array
    {
        $signature = $packet['signature'] ?? null;
        $source = $packet['source'] ?? null;
        if (! is_array($source) || ! is_string($signature) || strlen($this->sourceKey) !== 64
            || ! hash_equals(hash_hmac('sha256', RecoveryGraphRepository::hash($source), $this->sourceKey), $signature)) {
            throw new RuntimeException('GRAPH_SOURCE_SIGNATURE');
        }
        if (($source['version'] ?? null) !== 1 || ($source['schema'] ?? null) !== $this->graph->schemaHash()
            || ! is_array($source['rows'] ?? null) || ! is_array($source['files'] ?? null)) {
            throw new RuntimeException('GRAPH_SCHEMA');
        }
        $fileMap = [];
        foreach ($source['files'] as $file) {
            if (! is_array($file) || ! is_string($file['id'] ?? null) || isset($fileMap[$file['id']])) {
                throw new RuntimeException('GRAPH_FILES');
            }
            $fileMap[$file['id']] = $file;
        }
        $artifacts = $source['rows']['tenant_artifacts'] ?? null;
        if (! is_array($artifacts) || count($artifacts) !== count($fileMap)) {
            throw new RuntimeException('GRAPH_FILE_COVERAGE');
        }
        foreach ($artifacts as $record) {
            $file = $fileMap[$record['id'] ?? ''] ?? null;
            $contents = is_array($file) && is_string($file['contents'] ?? null) ? base64_decode($file['contents'], true) : false;
            if (! is_string($contents) || ($record['disk'] ?? null) !== 'local' || ($record['ready'] ?? null) !== true
                || strlen($contents) !== ($record['bytes'] ?? null) || ($record['expires_at'] ?? null) !== null) {
                throw new RuntimeException('GRAPH_FILE_COVERAGE');
            }
        }

        return $source;
    }

    /** @param array<string,mixed> $packet
     * @param  list<string>  $preserve
     * @return array<string,mixed>
     */
    public function plan(TenantContext $context, array $packet, string $confirmation, array $preserve): array
    {
        $this->authorize($context);
        $source = $this->authenticate($packet);
        if ($confirmation !== $context->organizationId || ($source['tenant'] ?? null) !== $context->organizationId
            || ($source['schema'] ?? null) !== $this->graph->schemaHash() || ! is_array($source['rows'] ?? null)
            || ! is_array($source['files'] ?? null)) {
            throw new RuntimeException('GRAPH_SCOPE_OR_SCHEMA');
        }
        $review = $this->graph->plan($context->organizationId, $source['rows'], $preserve);
        $this->files($context, $source['files'], false);
        $plan = ['operation' => (string) Str::uuid(), 'tenant' => $context->organizationId, 'packet' => $packet,
            'preserve' => $preserve, 'review' => $review];

        return ['plan' => $plan, 'signature' => hash_hmac('sha256', RecoveryGraphRepository::hash($plan), $this->token)];
    }

    /** @param array<string,mixed> $signed
     * @return array<string,mixed>
     */
    public function apply(TenantContext $context, array $signed, string $fault = ''): array
    {
        $this->authorize($context);
        $plan = $signed['plan'] ?? null;
        if (! is_array($plan) || ! is_string($signed['signature'] ?? null)
            || ! hash_equals(hash_hmac('sha256', RecoveryGraphRepository::hash($plan), $this->token), $signed['signature'])
            || ($plan['tenant'] ?? null) !== $context->organizationId || ! is_string($plan['operation'] ?? null)
            || ! Str::isUuid($plan['operation']) || ! is_array($plan['packet'] ?? null) || ! is_array($plan['review'] ?? null)) {
            throw new RuntimeException('GRAPH_PLAN_SIGNATURE');
        }
        $source = $this->authenticate($plan['packet']);
        if (! is_array($source['rows'] ?? null) || ! is_array($source['files'] ?? null)
            || ! is_array($plan['preserve'] ?? null) || ! array_is_list($plan['preserve']) || ($plan['review']['unresolved'] ?? ['missing']) !== []) {
            throw new RuntimeException('GRAPH_UNRESOLVED');
        }
        $preserve = [];
        foreach ($plan['preserve'] as $choice) {
            if (! is_string($choice)) {
                throw new RuntimeException('GRAPH_DECISION');
            }
            $preserve[] = $choice;
        }
        $journalPath = $this->root.'/graph-journal.enc';
        $marker = $this->db->table('tenant_portability_operations')->where('organization_id', $context->organizationId)
            ->where('request_id', $plan['operation'])->where('kind', 'recovery')->first();
        if ($marker === null && RecoveryGraphRepository::hash($this->graph->plan($context->organizationId, $source['rows'], $preserve)) !== RecoveryGraphRepository::hash($plan['review'])) {
            throw new RuntimeException('GRAPH_STALE');
        }
        if (is_link($journalPath)) {
            throw new RuntimeException('GRAPH_JOURNAL');
        }
        if (file_exists($journalPath)) {
            $saved = $this->app->make(Encrypter::class)->decryptString((string) file_get_contents($journalPath));
            if ($saved !== json_encode($signed, JSON_THROW_ON_ERROR)) {
                throw new RuntimeException('GRAPH_JOURNAL_CONFLICT');
            }
        } else {
            $this->write($journalPath, $this->app->make(Encrypter::class)->encryptString(json_encode($signed, JSON_THROW_ON_ERROR)));
        }
        if ($marker === null) {
            $this->db->transaction(function () use ($context, $source, $plan, $preserve, $fault): void {
                $this->app->make(TenantBusinessGuard::class)->lock($context);
                $this->authorize($context);
                $current = $this->graph->plan($context->organizationId, $source['rows'], $preserve);
                if (RecoveryGraphRepository::hash($current) !== RecoveryGraphRepository::hash($plan['review'])) {
                    throw new RuntimeException('GRAPH_STALE');
                }
                foreach ($current['ordered'] as $key) {
                    $record = $current['missing'][$key];
                    $this->db->table($record['table'])->insert($record['data']);
                    if ($fault === 'rollback') {
                        throw new RuntimeException('GRAPH_ROLLBACK');
                    }
                }
                $this->catalog->create($context->organizationId, ['actor_user_id' => $context->actorUserId,
                    'request_id' => $plan['operation'], 'kind' => 'recovery', 'status' => 'pending',
                    'reason' => 'UCG-OPS-005 synthetic graph experiment',
                    'details' => ['fixture' => 'graph-v1', 'plan_hash' => RecoveryGraphRepository::hash($plan),
                        'source_hash' => RecoveryGraphRepository::hash($source), 'inserted_rows' => count($current['missing'])]]);
                $this->app->make(TenantAuditJournal::class)->record($context->organizationId,
                    new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                        $context->organizationId, AuditReason::Domain, $plan['operation'], after: ['version' => $plan['operation'], 'confirmed' => true]));
            });
            if ($fault === 'crash-after-sql') {
                exit(73);
            }
            $marker = $this->db->table('tenant_portability_operations')->where('organization_id', $context->organizationId)
                ->where('request_id', $plan['operation'])->where('kind', 'recovery')->sole();
        }
        $details = json_decode($marker->details, true, flags: JSON_THROW_ON_ERROR);
        if (($details['plan_hash'] ?? null) !== RecoveryGraphRepository::hash($plan)) {
            throw new RuntimeException('GRAPH_MARKER');
        }
        foreach ($plan['review']['missing'] as $record) {
            $key = DatabasePortabilityRepository::TABLES[$record['table']];
            $restored = $this->db->table($record['table'])->where('organization_id', $context->organizationId)
                ->where($key, $record['data'][$key])->first(array_keys($record['data']));
            if ($restored === null || RecoveryGraphRepository::hash($restored) !== RecoveryGraphRepository::hash($record['data'])) {
                throw new RuntimeException('GRAPH_RECOVERED_ROW_CHANGED');
            }
        }
        $fileActions = $this->files($context, $source['files'], true);
        foreach ($fileActions as $action) {
            if (! file_exists($action['path'])) {
                $stage = $this->root.'/graph-file-'.hash('sha256', $action['contents']);
                if (! file_exists($stage)) {
                    $this->write($stage, $action['contents']);
                }
                if (is_link($stage) || hash_file('sha256', $stage) !== hash('sha256', $action['contents']) || ! link($stage, $action['path'])) {
                    throw new RuntimeException('GRAPH_FILE_INSTALL');
                }
            }
            if ($fault === 'crash-after-file') {
                exit(74);
            }
        }
        if ($marker->status !== 'completed') {
            $this->catalog->update($context->organizationId, $marker->id, ['status' => 'completed']);
        }

        return ['completed' => true, 'insertedRows' => $details['inserted_rows'], 'operation' => $plan['operation']];
    }

    /** @return array<string,mixed> */
    public function resume(TenantContext $context, string $fault = ''): array
    {
        $path = $this->root.'/graph-journal.enc';
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('GRAPH_JOURNAL');
        }
        $saved = $this->app->make(Encrypter::class)->decryptString((string) file_get_contents($path));
        $signed = json_decode($saved, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($signed)) {
            throw new RuntimeException('GRAPH_JOURNAL');
        }

        return $this->apply($context, $signed, $fault);
    }

    private function authorize(TenantContext $context): void
    {
        foreach (['sports.write', 'teams.manage', 'administrative.write'] as $permission) {
            $this->app->make(TenantAuthorization::class)->authorize($context, $permission);
        }
        if (is_file($this->root.'/graph-hold') || $this->catalog->hasPurge($context->organizationId)) {
            throw new RuntimeException('GRAPH_HOLD_OR_PURGE');
        }
    }

    /** @param array<mixed> $files
     * @return list<array{path:string,contents:string}>
     */
    private function files(TenantContext $context, array $files, bool $allowIdentical): array
    {
        if (count($files) > 100) {
            throw new RuntimeException('GRAPH_FILES');
        }
        $parent = realpath(storage_path('app/private/tenants/'.$context->organizationId.'/artifacts'));
        if ($parent !== realpath(storage_path('app/private')).DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.$context->organizationId.DIRECTORY_SEPARATOR.'artifacts') {
            throw new RuntimeException('GRAPH_FILE_PATH');
        }
        $actions = [];
        $seen = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['id'] ?? null) || ! Str::isUuid($file['id'])
                || strtolower($file['id']) !== $file['id'] || isset($seen[$file['id']]) || ! is_string($file['contents'] ?? null)) {
                throw new RuntimeException('GRAPH_FILES');
            }
            $seen[$file['id']] = true;
            $contents = base64_decode($file['contents'], true);
            if (! is_string($contents) || strlen($contents) > 8192 || hash('sha256', $contents) !== ($file['sha256'] ?? null)) {
                throw new RuntimeException('GRAPH_FILE_HASH');
            }
            $path = $parent.DIRECTORY_SEPARATOR.$file['id'];
            if (is_link($path) || (file_exists($path) && (! $allowIdentical || hash_file('sha256', $path) !== $file['sha256']))) {
                throw new RuntimeException('GRAPH_FILE_CONFLICT');
            }
            $actions[] = ['path' => $path, 'contents' => $contents];
        }

        return $actions;
    }

    private function write(string $path, string $contents): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('GRAPH_JOURNAL_WRITE');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('GRAPH_JOURNAL_WRITE');
            }
        } finally {
            fclose($handle);
        }
    }
}
