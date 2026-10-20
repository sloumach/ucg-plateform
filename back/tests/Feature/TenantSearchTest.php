<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\ExternalCompetitionDocumentService;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Contracts\Application\Services\ContractFinancialService;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class TenantSearchTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess;

    /** @param list<string> $permissions
     * @return array<string,mixed> */
    private function scenario(array $permissions = ['sports.read']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $assignment = $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return compact('org', 'user', 'assignment') + ['root' => '/api/v1/tenants/'.$org->id.'/search'];
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $filters */
    private function url(array $s, array $filters = []): string
    {
        return $s['root'].'?'.http_build_query(['type' => 'people', 'q' => 'Al', ...$filters]);
    }

    public function test_people_search_and_suggestions_only_expose_allowed_pseudonyms_in_the_current_tenant(): void
    {
        $s = $this->scenario();
        Person::factory()->create(['organization_id' => $s['org']->id, 'pseudonym' => 'Alpha <script>', 'first_name' => 'Secret identity', 'contact_email' => 'hidden@example.test']);
        Person::factory()->create(['pseudonym' => 'Alpha foreign']);
        $this->getJson($s['root'].'/types')->assertOk()->assertJsonPath('data.types', ['people', 'teams', 'matches']);
        $r = $this->getJson($this->url($s, ['mode' => 'suggest']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.label', 'Alpha <script>')->assertJsonPath('data.meta.has_more', false);
        $this->assertSame(['id', 'label', 'reference', 'parent_id'], array_keys($r->json('data.items.0')));
        $body = $r->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Secret identity', $body);
        $this->assertStringNotContainsString('hidden@example.test', $body);
        $this->getJson($this->url($s, ['q' => 'Secret']))->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_prefix_matching_is_literal_and_unicode_aware(): void
    {
        $s = $this->scenario();
        foreach (['Équipe', 'Al%literal', 'Al_normal', 'Alpha'] as $pseudonym) {
            Person::factory()->create(['organization_id' => $s['org']->id, 'pseudonym' => $pseudonym]);
        }
        $this->getJson($this->url($s, ['q' => 'éq']))->assertOk()->assertJsonPath('data.items.0.label', 'Équipe')->assertJsonCount(1, 'data.items');
        $this->getJson($this->url($s, ['q' => 'Al%']))->assertOk()->assertJsonPath('data.items.0.label', 'Al%literal')->assertJsonCount(1, 'data.items');
        $this->getJson($this->url($s, ['q' => 'Al_']))->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_scan_is_bounded_and_an_empty_segment_can_be_continued_without_losing_matches(): void
    {
        $s = $this->scenario();
        for ($i = 1; $i <= 101; $i++) {
            Person::factory()->create(['id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'organization_id' => $s['org']->id, 'pseudonym' => $i === 101 ? 'Target' : 'Other']);
        }
        $r = $this->getJson($this->url($s, ['q' => 'Ta']))->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.meta.examined', 100)->assertJsonPath('data.meta.has_more', true);
        $this->getJson($this->url($s, ['q' => 'Ta', 'cursor' => $r->json('data.meta.next_cursor')]))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.label', 'Target')->assertJsonPath('data.meta.has_more', false);
    }

    public function test_suggestions_and_cursor_pages_have_no_duplicates_and_limits_are_respected(): void
    {
        $s = $this->scenario();
        Person::factory()->count(7)->create(['organization_id' => $s['org']->id, 'pseudonym' => 'Alpha']);
        $r = $this->getJson($this->url($s, ['mode' => 'suggest', 'per_page' => 20]))->assertOk()->assertJsonCount(5, 'data.items')->assertJsonPath('data.meta.per_page', 5);
        $next = $this->getJson($this->url($s, ['mode' => 'suggest', 'per_page' => 20, 'cursor' => $r->json('data.meta.next_cursor')]))->assertOk()->assertJsonCount(2, 'data.items');
        $this->assertSame([], array_intersect(array_column($r->json('data.items'), 'id'), array_column($next->json('data.items'), 'id')));
    }

    public function test_cursors_reject_tampering_expiry_changed_filters_actor_and_tenant(): void
    {
        $this->freezeTime();
        $s = $this->scenario();
        Person::factory()->count(2)->create(['organization_id' => $s['org']->id, 'pseudonym' => 'Alpha']);
        $r = $this->getJson($this->url($s, ['per_page' => 1]))->assertOk();
        $cursor = $r->json('data.meta.next_cursor');
        foreach ([['cursor' => 'tampered'], ['cursor' => $cursor, 'q' => 'Other'], ['cursor' => $cursor, 'type' => 'teams'], ['cursor' => $cursor, 'per_page' => 2]] as $change) {
            $this->getJson($this->url($s, ['per_page' => 1, ...$change]))->assertUnprocessable()->assertJsonValidationErrors('cursor');
        }
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['user'], ['sports.read']);
        $this->getJson($this->url([...$s, 'root' => '/api/v1/tenants/'.$other->id.'/search'], ['per_page' => 1, 'cursor' => $cursor]))->assertUnprocessable();
        $actor = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $actor, ['sports.read']);
        $this->actingAsWithMfa($actor);
        $this->getJson($this->url($s, ['per_page' => 1, 'cursor' => $cursor]))->assertUnprocessable();
        $this->actingAsWithMfa($s['user']);
        $this->travel(15)->minutes();
        $this->getJson($this->url($s, ['per_page' => 1, 'cursor' => $cursor]))->assertUnprocessable();
    }

    #[TestWith(['q', 'a'])]
    #[TestWith(['q', '  '])]
    #[TestWith(['q', "Al\npha"])]
    #[TestWith(['q', ['Alpha']])]
    #[TestWith(['per_page', 21])]
    #[TestWith(['per_page', 0])]
    #[TestWith(['type', 'users'])]
    #[TestWith(['mode', 'bulk'])]
    #[TestWith(['organization_id', 'foreign'])]
    #[TestWith(['page', 2])]
    public function test_validation_rejects_invalid_and_unknown_filters(string $field, mixed $value): void
    {
        $s = $this->scenario();
        $this->getJson($this->url($s, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_permissions_are_rechecked_and_another_tenant_is_not_disclosed(): void
    {
        $s = $this->scenario(['administrative.read']);
        $this->getJson($s['root'].'/types')->assertOk()->assertJsonPath('data.types', ['contract_documents']);
        $this->getJson($this->url($s))->assertForbidden();
        $other = Organization::factory()->create();
        $this->getJson($this->url([...$s, 'root' => '/api/v1/tenants/'.$other->id.'/search']))->assertNotFound();
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        $this->getJson($s['root'].'/types')->assertForbidden();
    }

    public function test_guests_are_rejected_and_gate_is_bound_to_actor(): void
    {
        $s = $this->scenario();
        $context = $this->personContext($s['org'], $s['user']);
        $this->assertTrue(Gate::forUser($s['user'])->allows('search.viewAny', $context));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('search.viewAny', $context));
        auth()->forgetGuards();
        $this->getJson($this->url($s))->assertUnauthorized();
    }

    public function test_teams_and_encrypted_competitions_are_searchable_without_contacts(): void
    {
        $s = $this->scenario(['sports.read', 'sports.competitions.read']);
        Team::factory()->create(['organization_id' => $s['org']->id, 'name' => 'Alpha team']);
        Team::factory()->create(['name' => 'Alpha foreign']);
        ExternalCompetition::factory()->create(['organization_id' => $s['org']->id, 'name' => 'Alpha cup', 'reference' => 'CUP-2026', 'contacts' => [['name' => 'Secret contact', 'role' => null, 'email' => 'secret@example.test', 'phone' => null]]]);
        ExternalCompetition::factory()->create(['name' => 'Alpha foreign']);
        $this->getJson($this->url($s, ['type' => 'teams']))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.label', 'Alpha team');
        $r = $this->getJson($this->url($s, ['type' => 'competitions', 'q' => 'CUP-']))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.label', 'Alpha cup');
        $body = $r->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Secret contact', $body);
        $this->getJson($this->url($s, ['type' => 'competitions', 'q' => 'Secret']))->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_private_matches_are_filtered_before_searching_and_decrypting(): void
    {
        $s = $this->scenario();
        MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'title' => 'Alpha shared', 'visibility' => 'organization', 'sharing_authorized_by' => $s['user']->id, 'sharing_authorized_at' => now()]);
        $hidden = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'title' => 'Alpha hidden']);
        DB::table('matches')->where('id', $hidden->id)->update(['title' => 'corrupt ciphertext', 'version' => (string) Str::uuid()]);
        MatchRecord::factory()->create(['title' => 'Alpha foreign']);
        $this->getJson($this->url($s, ['type' => 'matches']))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.label', 'Alpha shared');
    }

    public function test_contract_document_search_excludes_financially_restricted_metadata(): void
    {
        $s = $this->scenario(['administrative.read']);
        $document = ContractDocument::factory()->create(['organization_id' => $s['org']->id, 'contract_id' => ContractRecord::factory()->create(['organization_id' => $s['org']->id, 'responsible_person_id' => Person::factory()->create(['organization_id' => $s['org']->id])->id])->id, 'title' => 'Alpha contract']);
        ContractDocument::factory()->create(['title' => 'Alpha foreign']);
        $this->getJson($this->url($s, ['type' => 'contract_documents']))->assertOk()->assertJsonCount(1, 'data.items');
        $finance = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $finance, ['administrative.read', 'administrative.write', 'financial.read', 'financial.write']);
        $contract = ContractRecord::query()->findOrFail($document->contract_id);
        app(ContractFinancialService::class)->save($this->personContext($s['org'], $finance), $contract->id, ['contract_version' => $contract->version, 'expected_version' => null, 'terms' => ['currency' => 'EUR', 'remuneration' => null, 'remuneration_period' => null, 'bonus' => '100.00', 'prize_share_percent' => null, 'payment_terms' => null]]);
        $this->getJson($this->url($s, ['type' => 'contract_documents']))->assertOk()->assertJsonCount(0, 'data.items');
        $this->actingAsWithMfa($finance);
        $this->getJson($this->url($s, ['type' => 'contract_documents']))->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_competition_documents_searches_only_current_titles_and_never_the_body(): void
    {
        $s = $this->scenario(['sports.read', 'sports.competitions.read', 'sports.competitions.write']);
        $competition = ExternalCompetition::factory()->create(['organization_id' => $s['org']->id]);
        $context = $this->personContext($s['org'], $s['user']);
        $service = app(ExternalCompetitionDocumentService::class);
        $doc = $service->save($context, $competition->id, null, ['competition_version' => $competition->version, 'kind' => 'rules', 'title' => 'Old title', 'body' => 'Alpha confidential body', 'reason' => 'Verified external rules for the season.'], null);
        $service->save($context, $competition->id, $doc->document_id, ['competition_version' => $competition->version, 'version' => $doc->id, 'title' => 'Alpha current', 'reason' => 'Verified external amended title.'], null);
        $this->getJson($this->url($s, ['type' => 'competition_documents', 'q' => 'Old']))->assertOk()->assertJsonCount(0, 'data.items');
        $r = $this->getJson($this->url($s, ['type' => 'competition_documents']))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.label', 'Alpha current');
        $body = $r->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('confidential body', $body);
    }

    public function test_corrupt_authorized_source_fails_closed_with_a_localized_error(): void
    {
        $s = $this->scenario(['sports.read', 'sports.competitions.read']);
        $record = ExternalCompetition::factory()->make(['organization_id' => $s['org']->id]);
        $record->setRawAttributes([...$record->getAttributes(), 'name' => 'corrupt ciphertext']);
        $record->save();
        $this->getJson($this->url($s, ['type' => 'competitions']))->assertConflict()->assertJsonPath('code', 'SEARCH_SOURCE_UNAVAILABLE')->assertJsonPath('message', __('search.unavailable'));
    }

    #[TestWith(['array'])]
    #[TestWith(['database'])]
    public function test_query_count_is_constant_when_results_grow(string $store): void
    {
        config(['tenancy.cache_store' => $store]);
        $s = $this->scenario();
        Person::factory()->create(['organization_id' => $s['org']->id, 'pseudonym' => 'Alpha']);
        $this->getJson($this->url($s))->assertOk();
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->getJson($this->url($s))->assertOk()->assertJsonCount(1, 'data.items');
            $queries = count(DB::getQueryLog());
            Person::factory()->count(4)->create(['organization_id' => $s['org']->id, 'pseudonym' => 'Alpha']);
            DB::flushQueryLog();
            $this->getJson($this->url($s))->assertOk()->assertJsonCount(5, 'data.items');
            $this->assertSame($queries, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_search_limiter_is_shared_by_modes_and_scoped_to_tenant_actor(): void
    {
        $s = $this->scenario();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson($this->url($s, ['mode' => $i % 2 ? 'suggest' : 'search']))->assertOk();
        }
        $this->getJson($this->url($s))->assertTooManyRequests();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['user'], ['sports.read']);
        $this->getJson($this->url([...$s, 'root' => '/api/v1/tenants/'.$other->id.'/search']))->assertOk();
    }
}
