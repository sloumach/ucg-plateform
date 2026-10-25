<?php

use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractObligation;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractTransition;
use App\Modules\People\Application\Data\PersonData;
use App\Modules\People\Application\Services\PersonService;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabasePortabilityRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Tests\Fixtures\RecoveryGraphJournal;
use Tests\Fixtures\RecoveryGraphRepository;

if (getenv('UCG_RECOVERY_FIXTURE') !== 'v1' || getenv('UCG_BROWSER_ENGINE') !== 'pgsql'
    || ! in_array($argv[1] ?? '', ['base', 'graph', 'export-alpha', 'export-beta', 'plan', 'apply', 'rollback', 'crash-after-sql', 'resume', 'crash-after-file', 'hold', 'unhold', 'alpha-change', 'beta-change', 'verify', 'changed-row-probe', 'purge-marker'], true)) {
    throw new RuntimeException('Owned PostgreSQL graph fixture required.');
}
try {
    /** @var Application $app */
    $app = require __DIR__.'/browser-application.php';
    $app->make(Kernel::class)->bootstrap();
    $db = $app->make(DatabaseManager::class)->connection();
    $alpha = Organization::query()->where('slug', 'recovery-alpha')->sole();
    $beta = Organization::query()->where('slug', 'recovery-beta')->sole();
    $context = $app->make(TenantResolver::class)->resolve($alpha->id, '', $alpha->owner_user_id, (string) Str::uuid());
    $catalog = $app->make(DatabasePortabilityRepository::class);
    $graph = new RecoveryGraphRepository($db, $catalog);
    $root = (string) getenv('UCG_BROWSER_ROOT');
    $sourceKey = (string) getenv('UCG_GRAPH_SOURCE_KEY');
    $journal = new RecoveryGraphJournal($app, $db, $graph, $catalog, $root, (string) getenv('UCG_BROWSER_TOKEN'), $sourceKey);
    $operation = $argv[1];

    if ($operation === 'base') {
        $game = GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->first()
            ?? GameCatalogueEntry::factory()->create(['key' => 'valorant', 'name' => 'VALORANT', 'default_enabled' => true,
                'definition' => ['platforms' => ['pc' => ['riot']], 'regions' => ['eu']]]);
        foreach ([$alpha, $beta] as $organization) {
            OrganizationGame::factory()->create(['organization_id' => $organization->id, 'game_id' => $game->id]);
            $role = OrganizationRole::factory()->create(['organization_id' => $organization->id,
                'permissions' => ['teams.manage'], 'privileged' => true]);
            OrganizationRoleAssignment::factory()->create(['organization_id' => $organization->id,
                'user_id' => $organization->owner_user_id, 'membership_id' => null, 'role_id' => $role->id, 'scope_id' => $organization->id]);
        }
        echo json_encode(['base' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'graph') {
        $game = GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->sole();
        $person = Person::factory()->create(['organization_id' => $alpha->id, 'pseudonym' => 'Graph person', 'first_name' => 'Fictitious graph', 'contact_email' => 'graph@example.test']);
        $profile = GameProfile::factory()->create(['organization_id' => $alpha->id, 'person_id' => $person->id]);
        ExternalGameAccount::factory()->create(['organization_id' => $alpha->id, 'game_profile_id' => $profile->id]);
        PersonPeriod::factory()->create(['organization_id' => $alpha->id, 'person_id' => $person->id,
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'status' => 'closed']);
        $team = Team::factory()->create(['organization_id' => $alpha->id, 'game_id' => $game->id, 'name' => 'Graph team']);
        $membership = TeamMembership::factory()->create(['organization_id' => $alpha->id, 'team_id' => $team->id, 'person_id' => $person->id]);
        $lineup = TeamLineup::factory()->create(['organization_id' => $alpha->id, 'team_id' => $team->id]);
        TeamLineupEntry::factory()->create(['organization_id' => $alpha->id, 'lineup_id' => $lineup->id, 'membership_id' => $membership->id]);
        $match = MatchRecord::factory()->create(['organization_id' => $alpha->id, 'game_id' => $game->id,
            'responsible_person_id' => $person->id, 'title' => 'Graph match']);
        (new MatchParticipant)->forceFill(['organization_id' => $alpha->id, 'match_id' => $match->id, 'slot' => 1,
            'kind' => 'team', 'team_id' => $team->id, 'reference_version' => $team->version, 'label' => 'Graph team'])->save();
        $contract = ContractRecord::factory()->create(['organization_id' => $alpha->id, 'responsible_person_id' => $person->id,
            'title' => 'Graph contract', 'created_by' => $alpha->owner_user_id, 'prepared_by' => $alpha->owner_user_id]);
        (new ContractParty)->forceFill(['organization_id' => $alpha->id, 'contract_id' => $contract->id, 'kind' => 'person',
            'person_id' => $person->id, 'name' => 'Fictitious graph', 'position' => 1])->save();
        (new ContractObligation)->forceFill(['organization_id' => $alpha->id, 'contract_id' => $contract->id,
            'title' => 'Fictitious obligation', 'position' => 1, 'status' => 'pending', 'version' => (string) Str::uuid()])->save();
        (new ContractTransition)->forceFill(['organization_id' => $alpha->id, 'contract_id' => $contract->id,
            'from_status' => null, 'to_status' => 'draft', 'contract_version' => $contract->version,
            'actor_user_id' => $alpha->owner_user_id, 'occurred_at' => now()])->save();
        $document = ContractDocument::factory()->create(['organization_id' => $alpha->id, 'contract_id' => $contract->id, 'created_by' => $alpha->owner_user_id]);
        $artifact = $db->table('tenant_artifacts')->where('organization_id', $alpha->id)->sole();
        $contents = $app->make(TenantArtifacts::class)->read($context, $artifact->id);
        (new ContractDocumentVersion)->forceFill(['organization_id' => $alpha->id, 'contract_id' => $contract->id,
            'document_id' => $document->id, 'artifact_id' => $artifact->id, 'number' => 1, 'purpose' => 'draft',
            'filename' => 'fictitious.txt', 'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents),
            'created_by' => $alpha->owner_user_id, 'created_at' => now()])->save();
        echo json_encode(['graph' => true, 'person' => $person->id], JSON_THROW_ON_ERROR);
        exit;
    }
    if (in_array($operation, ['export-alpha', 'export-beta'], true)) {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceKey) !== 1) {
            throw new RuntimeException('GRAPH_SOURCE_KEY');
        }
        $organization = $operation === 'export-alpha' ? $alpha : $beta;
        $scope = $app->make(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $files = [];
        foreach ($catalog->artifacts($organization->id, 100) as $artifact) {
            $contents = $app->make(TenantArtifacts::class)->read($scope, $artifact['id']);
            $files[] = ['id' => $artifact['id'], 'contents' => base64_encode($contents), 'sha256' => hash('sha256', $contents)];
        }
        $source = ['version' => 1, 'tenant' => $organization->id, 'schema' => $graph->schemaHash(), 'rows' => $graph->rows($organization->id), 'files' => $files];
        // Faults are confined to this owned fixture, to test authenticated but invalid input.
        switch (getenv('UCG_GRAPH_FAULT')) {
            case 'column': $source['rows']['people'][0]['unregistered_column'] = 'refuse';
                break;
            case 'reference': $source['rows']['person_game_profiles'][0]['person_id'] = (string) Str::uuid();
                break;
            case 'global-reference': $source['rows']['contracts'][0]['created_by'] = 2147483000;
                break;
            case 'state': $source['rows']['contracts'][0]['status'] = 'approved';
                break;
            case 'duplicate': $source['rows']['person_game_profiles'][] = $source['rows']['person_game_profiles'][0];
                break;
            case 'scope': $source['rows']['people'][0]['organization_id'] = $beta->id;
                break;
            case 'access': $source['rows']['organization_roles'][0]['id'] = (string) Str::uuid();
                break;
        }
        echo json_encode(['source' => $source, 'signature' => hash_hmac('sha256', RecoveryGraphRepository::hash($source), $sourceKey)], JSON_THROW_ON_ERROR);
        exit;
    }
    if (in_array($operation, ['alpha-change', 'beta-change'], true)) {
        $organization = $operation === 'alpha-change' ? $alpha : $beta;
        $scope = $app->make(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $person = Person::query()->where('organization_id', $organization->id)->orderBy('id')->firstOrFail();
        $app->make(PersonService::class)->update($scope, $person->id, new PersonData(['pseudonym' => $operation.' after plan'], $person->version));
        echo json_encode(['changed' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'hold') {
        if (file_put_contents($root.'/graph-hold', 'synthetic review hold') === false) {
            throw new RuntimeException('GRAPH_HOLD_WRITE');
        }
        echo json_encode(['held' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'unhold') {
        if (! unlink($root.'/graph-hold')) {
            throw new RuntimeException('GRAPH_HOLD_WRITE');
        }
        echo json_encode(['held' => false], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'purge-marker') {
        $catalog->create($alpha->id, ['actor_user_id' => $alpha->owner_user_id, 'request_id' => (string) Str::uuid(),
            'kind' => 'purge', 'status' => 'pending', 'reason' => 'Synthetic erasure marker; never executes purge']);
        echo json_encode(['marker' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'verify') {
        $person = Person::query()->where('organization_id', $alpha->id)->where('pseudonym', 'Graph person')->sole();
        $contract = ContractRecord::query()->where('organization_id', $alpha->id)->where('responsible_person_id', $person->id)->sole();
        $version = ContractDocumentVersion::query()->where('organization_id', $alpha->id)->where('contract_id', $contract->id)->sole();
        if ($person->first_name !== 'Fictitious graph' || $contract->title !== 'Graph contract'
            || $version->filename !== 'fictitious.txt'
            || hash('sha256', $app->make(TenantArtifacts::class)->read($context, $version->artifact_id)) !== $version->sha256) {
            throw new RuntimeException('GRAPH_VERIFY');
        }
        foreach (['person_periods', 'contract_transitions', 'contract_document_versions'] as $table) {
            try {
                $db->transaction(fn (): int => $db->table($table)->where('organization_id', $alpha->id)->delete());
                throw new RuntimeException('GRAPH_HISTORY_DISABLED');
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23514') {
                    throw $exception;
                }
            }
        }
        echo json_encode(['graph' => true, 'decryption' => true, 'historyProtected' => true,
            'markers' => $db->table('tenant_portability_operations')->where('organization_id', $alpha->id)->where('kind', 'recovery')->count()], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'changed-row-probe') {
        $db->transaction(function () use ($db, $alpha, $journal, $context): void {
            $db->table('people')->where('organization_id', $alpha->id)->where('pseudonym', 'Graph person')->update(['pseudonym' => 'Post-commit drift']);
            $journal->resume($context);
            throw new RuntimeException('GRAPH_DRIFT_ACCEPTED');
        });
    }
    if (in_array($operation, ['resume', 'crash-after-file'], true)) {
        echo json_encode($journal->resume($context, $operation === 'crash-after-file' ? $operation : ''), JSON_THROW_ON_ERROR);
        exit;
    }
    $raw = stream_get_contents(STDIN, 2097153);
    if (! is_string($raw) || strlen($raw) > 2097152) {
        throw new RuntimeException('GRAPH_INPUT');
    }
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($input)) {
        throw new RuntimeException('GRAPH_INPUT');
    }
    if ($operation === 'plan') {
        if (! is_array($input['packet'] ?? null) || ! is_string($input['confirmation'] ?? null) || ! is_array($input['preserve'] ?? null)
            || ! array_is_list($input['preserve']) || count($input['preserve']) > 100 || count(array_filter($input['preserve'], 'is_string')) !== count($input['preserve'])) {
            throw new RuntimeException('GRAPH_INPUT');
        }
        echo json_encode($journal->plan($context, $input['packet'], $input['confirmation'], $input['preserve']), JSON_THROW_ON_ERROR);
    } else {
        echo json_encode($journal->apply($context, $input, $operation === 'apply' ? '' : $operation), JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    $reason = preg_match('/^GRAPH_[A-Z_]+$/D', $exception->getMessage()) === 1 ? $exception->getMessage() : 'GRAPH_FIXTURE_FAILED';
    fwrite(STDERR, 'Graph fixture failed: '.$exception::class.PHP_EOL);
    echo json_encode(['refused' => $reason], JSON_THROW_ON_ERROR);
    exit(2);
}
