<?php

use App\Modules\Competition\Domain\Models\MatchGameInstance;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchScoreRules;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Seed scored game history in bounded batches without disabling any database guard.
 * These synthetic played games do not certify the result-approval workflow.
 *
 * @param  array<string,string>  $organizations
 * @param  array<string,list<string>>  $people
 * @return array{matches:array<string,list<string>>,games:array<string,list<string>>}
 */
function seedLoadGames(ConnectionInterface $database, array $organizations, array $people, int $owner): array
{
    $game = GameCatalogueEntry::factory()->create(['kind' => 'game', 'default_enabled' => true]);
    $parents = [];
    $ids = [];
    $gameIds = ['alpha' => [], 'beta' => [], 'gamma' => []];
    foreach (['alpha' => 800, 'beta' => 199, 'gamma' => 1] as $label => $count) {
        $ids[$label] = [];
        $rows = [];
        $participants = [];
        $personVersions = $database->table('people')->whereIn('id', array_slice($people[$label], 0, 2))->pluck('version', 'id');
        for ($index = 0; $index < $count; $index++) {
            $id = (string) Str::uuid();
            $ids[$label][] = $id;
            $match = MatchRecord::factory()->make(['id' => $id, 'organization_id' => $organizations[$label],
                'game_id' => $game->id, 'game_key' => $game->key, 'game_name' => $game->name,
                'format' => 'lobby', 'created_by' => $owner, 'responsible_person_id' => $people[$label][0],
                'starts_at' => now()->subYear()->startOfDay(), 'ends_at' => now()->subYear()->startOfDay()->addHour(),
                'visibility' => 'organization', 'sharing_authorized_by' => $owner, 'sharing_authorized_at' => now()]);
            $rows[] = [...$match->getAttributes(), 'created_at' => now(), 'updated_at' => now()];
            $results = [];
            foreach ([1, 2] as $slot) {
                $participantId = (string) Str::uuid();
                $participant = new MatchParticipant;
                $participant->forceFill(['id' => $participantId, 'organization_id' => $organizations[$label], 'match_id' => $id,
                    'slot' => $slot, 'kind' => 'person', 'person_id' => $people[$label][$slot - 1],
                    'team_id' => null, 'opponent_snapshot_id' => null, 'reference_version' => $personVersions[$people[$label][$slot - 1]], 'label' => 'Load participant '.$slot]);
                $participants[] = $participant->getAttributes();
                $results[] = ['participant_id' => $participantId, 'label' => 'Load participant '.$slot, 'score' => $slot, 'lineup_revision_id' => null];
            }
            $prototype = new MatchGameInstance;
            $prototype->forceFill(['organization_id' => $organizations[$label], 'match_id' => $id, 'actor_user_id' => $owner,
                'reason' => 'Synthetic QA-005 history', 'outcome' => 'played', 'map' => 'Load arena', 'mode' => null,
                'game_patch' => 'load-1', 'results' => $results, 'created_at' => now()->subYear()]);
            $parents[$id] = $prototype->getAttributes();
        }
        foreach (array_chunk($rows, 200) as $batch) {
            $database->table('matches')->insert($batch);
        }
        foreach (array_chunk($participants, 400) as $batch) {
            $database->table('match_participants')->insert($batch);
        }
    }
    foreach (['confirmed', 'in_progress'] as $status) {
        $database->table('matches')->update(['status' => $status, 'version' => (string) Str::uuid()]);
    }
    $version = $database->table('matches')->value('version');
    $rules = [];
    foreach ($parents as $id => $parent) {
        $rule = new MatchScoreRules;
        $rule->forceFill(['id' => (string) Str::uuid(), 'organization_id' => $parent['organization_id'], 'match_id' => $id,
            'match_version' => $version, 'actor_user_id' => $owner, 'reason' => 'Synthetic QA-005 rules',
            'created_at' => now()->subYear(), 'format' => 'lobby', 'game_limit' => 100, 'allow_draw' => false]);
        $rules[] = $rule->getAttributes();
    }
    foreach (array_chunk($rules, 200) as $batch) {
        $database->table('match_score_rules')->insert($batch);
    }
    for ($number = 1; $number <= 100; $number++) {
        $version = (string) Str::uuid();
        $database->transaction(function () use ($database, $parents, $number, $version, $ids, &$gameIds): void {
            $database->table('matches')->update(['version' => $version]);
            $rows = [];
            foreach ($parents as $parent) {
                $gameId = (string) Str::uuid();
                $rows[] = [...$parent, 'id' => $gameId, 'number' => $number, 'match_version' => $version];
                foreach (['alpha', 'beta', 'gamma'] as $label) {
                    if ($parent['match_id'] === $ids[$label][0]) {
                        $gameIds[$label][] = $gameId;
                    }
                }
            }
            foreach (array_chunk($rows, 200) as $batch) {
                $database->table('match_game_instances')->insert($batch);
            }
        });
    }

    return ['matches' => $ids, 'games' => $gameIds];
}
