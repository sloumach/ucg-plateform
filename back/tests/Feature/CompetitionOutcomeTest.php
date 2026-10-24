<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Application\Services\CompetitionOutcomeService;
use App\Modules\Competition\Domain\Models\CompetitionOutcomeRevision;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCompetitionOutcomes;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CompetitionOutcomeTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesCompetitionOutcomes,ProvidesExternalCompetitions,ProvidesPeopleAccess;

    public function test_sourced_rank_links_and_history_respect_the_current_match_audience(): void
    {
        $s = $this->outcomeScenario();
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data', null);
        $row = $this->patchJson($s['path'], $s['input'])->assertOk()->assertJsonPath('data.payload.place', 2)->assertJsonPath('data.matches.0.match.title', $s['match']->title)->json('data');
        $this->getJson($s['path'].'/choices?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.version', $s['match']->version);
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.matches.0.match', null)->assertDontSee($s['match']->id)->assertDontSee($s['match']->title);
        $this->getJson($s['path'].'/history')->assertOk()->assertDontSee($s['match']->id);
        $this->getJson($s['path'].'/matches/'.$row['matches'][0]['reference_id'].'/result')->assertNotFound();
        $this->actingAsWithMfa($s['writer']);
        $next = [...$s['input'], 'expected_version' => $row['version'], 'place' => 1, 'matches' => [['reference_id' => $row['matches'][0]['reference_id'], 'match_id' => null, 'match_version' => null]]];
        $this->patchJson($s['path'], $next)->assertOk()->assertJsonPath('data.number', 2);
        $this->getJson($s['path'].'/history?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.payload.place', 2);
        $this->assertDatabaseCount('competition_outcome_matches', 2);
        $this->assertStringNotContainsString('Rapport organisateur', DB::table('competition_outcome_revisions')->value('payload'));
        $this->assertArrayNotHasKey('payload', CompetitionOutcomeRevision::query()->firstOrFail()->toArray());
    }

    public function test_awards_require_financial_permissions_and_do_not_appear_in_sport(): void
    {
        $s = $this->outcomeScenario('awards', true);
        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $s['revision']['version'], 'received_minor' => 0, 'received_on' => '2026-10-18'])->assertOk()->assertJsonPath('data.payload.received_minor', 0);
        $this->outcomePermissions($s, ['sports.read', 'sports.competitions.read', 'sports.competitions.write']);
        foreach (['', '/history', '/choices'] as $suffix) {
            $this->getJson($s['path'].$suffix)->assertForbidden();
        }
        $this->patchJson($s['path'], $s['input'])->assertForbidden();
        $this->getJson(str_replace('/awards', '/sport', $s['path']))->assertOk()->assertJsonPath('data', null)->assertDontSee('100000');
        $this->getJson($s['root'])->assertOk()->assertDontSee('100000');
        $this->outcomePermissions($s, ['sports.read', 'sports.competitions.read', 'financial.read']);
        $this->getJson($s['path'])->assertOk();
        $this->patchJson($s['path'], $s['input'])->assertForbidden();
        $this->outcomePermissions($s, ['sports.read', 'sports.competitions.read', 'financial.read', 'financial.write']);
        $latest = $this->getJson($s['path'])->json('data.version');
        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $latest, 'received_minor' => 110000, 'received_on' => '2026-10-18'])->assertOk()->assertJsonPath('data.payload.received_minor', 110000);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('110000', $audit);
        $this->assertStringNotContainsString('Rapport organisateur', $audit);
    }

    public function test_stale_noop_and_archiving_preserve_the_previous_revision(): void
    {
        $s = $this->outcomeScenario('sport', true);
        $row = $s['revision'];
        $this->patchJson($s['path'], $s['input'])->assertConflict();
        $body = [...$s['input'], 'expected_version' => $row['version'], 'matches' => [['reference_id' => $row['matches'][0]['reference_id'], 'match_id' => null, 'match_version' => null]]];
        $this->patchJson($s['path'], $body)->assertUnprocessable();
        $this->patchJson($s['path'], [...$body, 'competition_version' => (string) Str::uuid(), 'place' => 1])->assertConflict();
        $this->patchJson($s['root'].'/'.$s['record']['id'], ['version' => $s['record']['version'], 'archive' => true, 'reason' => 'Compétition terminée, dossier conservé.'])->assertOk();
        $this->patchJson($s['path'], [...$body, 'place' => 1])->assertConflict()->assertJsonPath('code', 'COMPETITION_OUTCOME_ARCHIVED');
        $this->assertDatabaseCount('competition_outcome_revisions', 1);
        $this->assertDatabaseCount('competition_outcome_matches', 1);
    }

    #[Group('tenant-isolation')]
    public function test_match_ids_references_game_and_tenant_are_scoped(): void
    {
        $s = $this->outcomeScenario();
        $foreign = MatchRecord::factory()->create();
        $different = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'created_by' => $s['writer']->id]);
        $hidden = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id]);
        foreach ([$foreign, $different, $hidden] as $match) {
            $this->patchJson($s['path'], [...$s['input'], 'matches' => [['reference_id' => null, 'match_id' => $match->id, 'match_version' => $match->version]]])->assertNotFound();
        }
        $this->patchJson($s['path'], [...$s['input'], 'matches' => [['reference_id' => null, 'match_id' => $s['match']->id, 'match_version' => (string) Str::uuid()]]])->assertConflict();
        $this->patchJson($s['path'], [...$s['input'], 'matches' => [['reference_id' => (string) Str::uuid(), 'match_id' => null, 'match_version' => null]]])->assertNotFound();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['writer'], ['sports.read', 'sports.competitions.read', 'sports.competitions.write']);
        $path = '/api/v1/tenants/'.$other->id.'/external-competitions/'.$s['record']['id'].'/outcomes/sport';
        foreach (['', '/history', '/choices'] as $suffix) {
            $this->getJson($path.$suffix)->assertNotFound();
        }
        $this->patchJson($path, $s['input'])->assertNotFound();
        $this->assertDatabaseCount('competition_outcome_revisions', 0);
    }

    public function test_reader_cannot_modify_or_select_matches(): void
    {
        $s = $this->outcomeScenario();
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['path'])->assertOk();
        $this->patchJson($s['path'], $s['input'])->assertForbidden();
        $this->getJson($s['path'].'/choices')->assertForbidden();
        $this->assertDatabaseCount('competition_outcome_revisions', 0);
    }

    /** @return array<string,array{string,array<string,mixed>}> */
    public static function invalid(): array
    {
        return ['unknown' => ['sport', ['received_minor' => 100]], 'nested' => ['sport', ['matches.*.match_id' => 'invalid']], 'rank' => ['sport', ['place' => 17]], 'fractional rank' => ['sport', ['place' => 1.5]],
            'future' => ['sport', ['observed_on' => '2026-10-19']], 'source' => ['sport', ['source' => '   ']], 'url' => ['sport', ['source_url' => 'https://127.0.0.1/report']],
            'money float' => ['awards', ['announced_minor' => 1.1]], 'money string' => ['awards', ['announced_minor' => '100']], 'negative' => ['awards', ['received_minor' => -1]],
            'overflow' => ['awards', ['announced_minor' => 1000000000001]], 'currency' => ['awards', ['currency' => 'ZZZ']], 'receipt date' => ['awards', ['received_minor' => 100]], 'receipt amount' => ['awards', ['received_on' => '2026-10-18']]];
    }

    /** @param array<string,mixed> $invalid */
    #[DataProvider('invalid')]
    public function test_invalid_input_never_creates_a_revision(string $stream, array $invalid): void
    {
        $s = $this->outcomeScenario($stream);
        $this->patchJson($s['path'], [...$s['input'], ...$invalid])->assertUnprocessable();
        $this->assertDatabaseCount('competition_outcome_revisions', 0);
    }

    public function test_sql_guards_tenant_game_sequence_and_immutable_history(): void
    {
        $s = $this->outcomeScenario('sport', true);
        $row = (array) DB::table('competition_outcome_revisions')->first();
        $link = (array) DB::table('competition_outcome_matches')->first();
        foreach (['competition_outcome_revisions', 'competition_outcome_matches'] as $table) {
            $this->reject(fn () => DB::table($table)->delete());
            $this->reject(fn () => DB::table($table)->update(['id' => (string) Str::uuid()]));
        }
        foreach ([['stream' => 'invalid'], ['number' => 4], ['organization_id' => (string) Str::uuid()]] as $invalid) {
            $this->reject(fn () => DB::table('competition_outcome_revisions')->insert([...$row, 'id' => (string) Str::uuid(), ...$invalid]));
        }
        $foreign = MatchRecord::factory()->create();
        $otherGame = MatchRecord::factory()->create(['organization_id' => $s['org']->id]);
        foreach ([$foreign, $otherGame] as $match) {
            $this->reject(fn () => DB::table('competition_outcome_matches')->insert([...$link, 'id' => (string) Str::uuid(), 'reference_id' => (string) Str::uuid(), 'match_id' => $match->id]));
        }
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $migration = require database_path('migrations/2026_10_19_005858_create_competition_outcome_tracking.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_empty_schema_roundtrip_keeps_export_inventory_complete(): void
    {
        $migration = require database_path('migrations/2026_10_19_005858_create_competition_outcome_tracking.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('competition_outcome_revisions'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_audit_failure_rolls_back_revision_and_match_links(): void
    {
        $s = $this->outcomeScenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(CompetitionOutcomeService::class)->save($this->personContext($s['org'], $s['writer']), $s['record']['id'], 'sport', $s['input'], null);
            $this->fail('Audit must abort the write.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('competition_outcome_revisions', 0);
        $this->assertDatabaseCount('competition_outcome_matches', 0);
    }

    public function test_confirmed_context_and_mfa_are_required(): void
    {
        $s = $this->outcomeScenario();
        $revision = (string) Str::uuid();
        $this->withHeader('X-Tenant-Revision', $revision)->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $s['org']->id, 'actor_user_id' => $s['writer']->id, 'revision' => $revision, 'confirmed' => false]])->patchJson($s['path'], $s['input'])->assertConflict();
        $this->withSession([MfaSession::PROOF_KEY => null])->getJson($s['path'])->assertForbidden();
        $this->assertDatabaseCount('competition_outcome_revisions', 0);
    }

    #[Group('tenant-isolation')]
    public function test_export_is_encrypted_and_bounded_to_its_tenant_and_match_projection_is_batched(): void
    {
        $s = $this->outcomeScenario('sport', true);
        $export = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $owned = array_values(array_filter($export, fn (array $r): bool => in_array($r['table'], ['competition_outcome_revisions', 'competition_outcome_matches'], true)));
        $this->assertCount(2, $owned);
        $this->assertStringNotContainsString('Rapport organisateur', json_encode($owned, JSON_THROW_ON_ERROR));
        $row = CompetitionOutcomeRevision::query()->with('links')->firstOrFail();
        $service = app(CompetitionOutcomeService::class);
        $context = $this->personContext($s['org'], $s['writer']);
        $service->visible($context, [$row]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->visible($context, array_fill(0, 10, $row));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $matchReads = array_filter($queries, fn (array $q): bool => str_contains($q['query'], 'from "matches"'));
        $this->assertCount(1, $matchReads);
    }

    public function test_missing_base_permissions_and_actor_mismatch_are_denied_by_the_policy(): void
    {
        $s = $this->outcomeScenario();
        foreach ([[], ['financial.read', 'financial.write'], ['sports.read', 'financial.read', 'financial.write'], ['sports.competitions.read', 'financial.read', 'financial.write']] as $permissions) {
            $this->outcomePermissions($s, $permissions);
            $context = $this->personContext($s['org'], $s['writer']);
            $this->assertFalse(Gate::forUser($s['writer'])->allows('viewAny', [CompetitionOutcomeRevision::class, $context, 'awards']));
        }
        $this->outcomePermissions($s, ['sports.read', 'sports.competitions.read', 'financial.read', 'financial.write']);
        $this->assertFalse(Gate::forUser($s['reader'])->allows('manage', [CompetitionOutcomeRevision::class, $this->personContext($s['org'], $s['writer']), 'awards']));
    }

    public function test_a_linked_match_cannot_later_change_game_including_through_sql(): void
    {
        $s = $this->outcomeScenario('sport', true);
        $foreign = MatchRecord::factory()->create(['organization_id' => $s['org']->id]);
        $this->reject(fn () => DB::table('matches')->where('id', $s['match']->id)->update(['game_id' => $foreign->game_id, 'game_key' => $foreign->game_key, 'game_name' => $foreign->game_name, 'version' => (string) Str::uuid()]));
        $this->assertDatabaseHas('matches', ['id' => $s['match']->id, 'game_id' => $s['game']->id]);
    }

    public function test_nullable_duplicate_links_and_cap_are_checked_before_any_write(): void
    {
        $s = $this->outcomeScenario();
        $entry = $s['input']['matches'][0];
        $this->patchJson($s['path'], [...$s['input'], 'matches' => [$entry, $entry]])->assertUnprocessable();
        $this->patchJson($s['path'], [...$s['input'], 'matches' => array_fill(0, 21, $entry)])->assertUnprocessable();
        $input = $s['input'];
        unset($input['source_url']);
        $this->patchJson($s['path'], $input)->assertUnprocessable()->assertJsonValidationErrors('source_url');
        $this->assertDatabaseCount('competition_outcome_revisions', 0);
    }

    public function test_integer_money_validation_also_protects_direct_service_calls(): void
    {
        $s = $this->outcomeScenario('awards');
        try {
            app(CompetitionOutcomeService::class)->save($this->personContext($s['org'], $s['writer']), $s['record']['id'], 'awards', [...$s['input'], 'announced_minor' => 1.0], null);
            $this->fail('A float must be rejected before persistence.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('announced_minor', $e->errors());
        }
        $this->assertDatabaseCount('competition_outcome_revisions', 0);
    }

    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail('SQL must reject this mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
