<?php

use App\Modules\Competition\Application\Services\MatchResultService;
use App\Modules\Competition\Application\Services\MatchScoreService;
use App\Modules\Competition\Application\Services\MatchService;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractObligation;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractTransition;
use App\Modules\Identity\Domain\Models\User;
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
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
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
    || ! in_array($argv[1] ?? '', ['base', 'graph', 'protected', 'export-alpha', 'export-beta', 'plan', 'apply', 'rollback', 'crash-after-sql', 'resume', 'crash-after-file', 'hold', 'unhold', 'alpha-change', 'beta-change', 'verify', 'verify-protected', 'changed-row-probe', 'purge-marker', 'concurrency-status'], true)) {
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
    $checkpoint = static function (string $phase) use ($root): void {
        if (getenv('UCG_GRAPH_PAUSE') !== $phase) {
            return;
        }
        if (file_put_contents($root.'/graph-ready-'.$phase, 'ready', LOCK_EX) === false) {
            throw new RuntimeException('GRAPH_CHECKPOINT');
        }
        $deadline = hrtime(true) + 60_000_000_000;
        while (! is_file($root.'/graph-release-'.$phase)) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('GRAPH_CHECKPOINT_TIMEOUT');
            }
            usleep(10_000);
        }
    };
    $journal = new RecoveryGraphJournal($app, $db, $graph, $catalog, $root, (string) getenv('UCG_BROWSER_TOKEN'), $sourceKey, $checkpoint);
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
        $validator = User::factory()->withMfa()->create(['email' => 'recovery-validator@example.test']);
        $membership = OrganizationMembership::factory()->create(['organization_id' => $alpha->id, 'user_id' => $validator->id]);
        $role = OrganizationRole::factory()->create(['organization_id' => $alpha->id,
            'permissions' => ['administrative.read', 'administrative.write', 'administrative.validate', 'sports.read', 'sports.write', 'sports.validate'], 'privileged' => true]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $alpha->id, 'user_id' => $validator->id,
            'membership_id' => $membership->id, 'role_id' => $role->id, 'scope_id' => $alpha->id]);
        echo json_encode(['base' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'protected') {
        $validator = User::query()->where('email', 'recovery-validator@example.test')->sole();
        $reviewer = $app->make(TenantResolver::class)->resolve($alpha->id, '', $validator->id, (string) Str::uuid());
        $person = Person::query()->where('organization_id', $alpha->id)->where('pseudonym', 'Graph person')->sole();
        $contracts = $app->make(ContractService::class);
        $contract = ContractRecord::query()->where('organization_id', $alpha->id)->sole();
        $protectedPhase = 'GRAPH_PROTECTED_CONTRACT_DATES';
        $contract = $contracts->save($context, ['version' => $contract->version, 'starts_on' => now()->toDateString()], $contract->id);
        $protectedPhase = 'GRAPH_PROTECTED_CONTRACT_REVIEW';
        $contract = $contracts->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        $protectedPhase = 'GRAPH_PROTECTED_CONTRACT_APPROVAL';
        $contract = $contracts->transition($reviewer, $contract->id, ['version' => $contract->version, 'status' => 'approved']);
        $obligation = ContractObligation::query()->where('organization_id', $alpha->id)->where('contract_id', $contract->id)->sole();
        $contract = $contracts->updateObligation($context, $contract->id, $obligation->id,
            ['contract_version' => $contract->version, 'version' => $obligation->version, 'status' => 'completed']);
        $game = GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->sole();
        $other = Person::query()->where('organization_id', $alpha->id)->where('id', '!=', $person->id)->firstOrFail();
        $matches = $app->make(MatchService::class);
        $protectedPhase = 'GRAPH_PROTECTED_MATCH_CREATE';
        $match = $matches->save($context, ['game_id' => $game->id, 'title' => 'Protected recovery match', 'kind' => 'scrim', 'format' => 'bo1',
            'starts_at' => now()->addDays(3)->startOfHour()->format('Y-m-d\TH:i:sP'),
            'ends_at' => now()->addDays(3)->startOfHour()->addHour()->format('Y-m-d\TH:i:sP'), 'origin_timezone' => 'UTC',
            'responsible_person_id' => $person->id, 'participants' => [['kind' => 'person', 'source_id' => $person->id], ['kind' => 'person', 'source_id' => $other->id]]]);
        $protectedPhase = 'GRAPH_PROTECTED_MATCH_CONFIRM';
        $match = $matches->transition($context, $match->id, ['version' => $match->version, 'status' => 'confirmed', 'reason' => 'Synthetic recovery qualification.']);
        $match = $matches->transition($context, $match->id, ['version' => $match->version, 'status' => 'in_progress', 'reason' => 'Synthetic recovery qualification.']);
        $protectedPhase = 'GRAPH_PROTECTED_MATCH_SCORES';
        $scores = $app->make(MatchScoreService::class);
        $rules = $scores->save($context, $match->id, ['version' => $match->version, 'allow_draw' => false, 'game_limit' => 1,
            'reason' => 'Synthetic recovery score rules.'], true, null);
        $firstParticipant = $match->participants->firstOrFail();
        $secondParticipant = $match->participants->skip(1)->firstOrFail();
        $gameScore = $scores->save($context, $match->id, ['version' => $rules['match_version'], 'outcome' => 'played',
            'map' => 'Fictitious recovery map', 'game_patch' => 'fixture-v1', 'reason' => 'Synthetic recovery match scores.',
            'results' => [['participant_id' => $firstParticipant->id, 'score' => 13], ['participant_id' => $secondParticipant->id, 'score' => 7]]], false, null);
        $results = $app->make(MatchResultService::class);
        $protectedPhase = 'GRAPH_PROTECTED_MATCH_SUBMIT';
        $submitted = $results->change($context, $match->id, ['version' => $gameScore['match_version'], 'outcome' => 'played',
            'proof_reference' => 'Fictitious recovery evidence',
            'confirm_proof' => true, 'reason' => 'Synthetic recovery qualification.'], 'submit');
        $protectedPhase = 'GRAPH_PROTECTED_MATCH_APPROVAL';
        $approved = $results->change($reviewer, $match->id, ['version' => $submitted['match_version'], 'submission_id' => $submitted['submission']['id'],
            'confirm_evidence' => true, 'reason' => 'Independent synthetic evidence review.'], 'approve');
        echo json_encode(['contract' => $contract->status, 'match' => $approved['status'], 'independent' => $validator->id !== $alpha->owner_user_id], JSON_THROW_ON_ERROR);
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
        if (getenv('UCG_GRAPH_SELECTION') === 'without-matches') {
            foreach (array_keys($source['rows']) as $table) {
                if ($table === 'matches' || str_starts_with($table, 'match_')) {
                    $source['rows'][$table] = [];
                }
            }
        }
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
            case 'history': array_pop($source['rows']['contract_transitions']);
                break;
            case 'result-proof': array_pop($source['rows']['match_result_events']);
                break;
            case 'self-approval': $source['rows']['contracts'][0]['approved_by'] = $source['rows']['contracts'][0]['prepared_by'];
                break;
            case 'game-order': $source['rows']['match_game_instances'][0]['number'] = 2;
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
        if (getenv('UCG_GRAPH_WRITER_PROBE') === 'v1') {
            $db->select("SELECT set_config('application_name', 'ucg-graph-writer', false)");
        }
        $organization = $operation === 'alpha-change' ? $alpha : $beta;
        $scope = $app->make(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $person = Person::query()->where('organization_id', $organization->id)
            ->when(getenv('UCG_GRAPH_WRITER_PROBE') === 'v1', fn ($query) => $query->where('pseudonym', '!=', 'Graph person'))
            ->orderBy('id')->firstOrFail();
        $app->make(PersonService::class)->update($scope, $person->id, new PersonData(['pseudonym' => $operation.' after plan'], $person->version));
        echo json_encode(['changed' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($operation === 'concurrency-status') {
        echo json_encode(['waitingWriters' => (int) $db->scalar("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND application_name='ucg-graph-writer' AND wait_event_type='Lock'"),
            'markers' => $db->table('tenant_portability_operations')->where('organization_id', $alpha->id)->where('kind', 'recovery')->count()], JSON_THROW_ON_ERROR);
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
    if ($operation === 'verify-protected') {
        $contract = ContractRecord::query()->where('organization_id', $alpha->id)->sole();
        $match = MatchRecord::query()->where('organization_id', $alpha->id)->where('status', 'validated')->sole();
        foreach (['match_result_events', 'match_game_instances', 'match_score_rules', 'match_status_events'] as $table) {
            try {
                $db->transaction(function () use ($db, $table, $alpha): void {
                    $db->table($table)->where('organization_id', $alpha->id)->delete();
                    throw new RuntimeException('GRAPH_HISTORY_DISABLED');
                });
            } catch (QueryException $exception) {
                if (! in_array($exception->getCode(), ['23514', 'P0001'], true)) {
                    throw $exception;
                }
            }
        }
        echo json_encode(['contract' => $contract->status, 'match' => $match->status,
            'independent' => $contract->approved_by !== $contract->prepared_by,
            'completedObligations' => $db->table('contract_obligations')->where('organization_id', $alpha->id)->where('status', 'completed')->count(),
            'scoreGames' => $db->table('match_game_instances')->where('organization_id', $alpha->id)->count(),
            'resultEvents' => $db->table('match_result_events')->where('organization_id', $alpha->id)->count(),
            'contractTransitions' => $db->table('contract_transitions')->where('organization_id', $alpha->id)->count(), 'historyProtected' => true], JSON_THROW_ON_ERROR);
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
    if ($reason === 'GRAPH_FIXTURE_FAILED' && isset($protectedPhase)) {
        $reason = $protectedPhase;
    }
    fwrite(STDERR, 'Graph fixture failed: '.$exception::class.PHP_EOL);
    echo json_encode(['refused' => $reason], JSON_THROW_ON_ERROR);
    exit(2);
}
