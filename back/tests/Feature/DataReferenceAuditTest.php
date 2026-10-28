<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Contracts\DataPreparationReferences as PeopleReferences;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Reporting\Application\Services\DataPreparationService;
use App\Modules\Reporting\Application\Services\DataReferenceAuditService;
use App\Modules\Teams\Application\Contracts\DataPreparationReferences;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\DataPreparationContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class DataReferenceAuditTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_current_tenant_references_and_retained_pseudonyms_are_checked_without_writes_or_values_in_output(): void
    {
        $owner = User::factory()->create();
        $this->actingAsWithMfa($owner);
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->grantOwnerRead($organization, $owner);
        $person = Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'PRIVATE_Alpha']);
        $archived = Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'PRIVATE_Archived', 'deleted_at' => now()]);
        $foreign = Person::factory()->create(['pseudonym' => 'PRIVATE_Foreign']);
        $items = [
            $this->item('person_id', $person->id, 'present'),
            $this->item('person_id', $foreign->id, 'present'),
            $this->item('person_pseudonym', 'private_alpha', 'absent'),
            $this->item('person_pseudonym', 'private_archived', 'absent'),
            $this->item('person_id', $archived->id, 'present'),
        ];
        $path = $this->bundle($organization->id, $items);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $report = app(DataReferenceAuditService::class)->audit($path, $organization->id, 'checks.json', $owner->id);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(5, $report['checked_count']);
        $this->assertCount(4, $report['issues']);
        $this->assertTrue($report['database_checked']);
        $this->assertFalse($report['coverage_verified']);
        $this->assertFalse($report['import_authorized']);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
        }
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach ([$person->id, $foreign->id, 'PRIVATE', 'private_alpha', 'private_archived', 'checks.json'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertDatabaseCount('people', 3);
        $this->assertSame(2, Artisan::call('data:references', ['manifest' => $path, 'checks' => 'checks.json', '--organization' => $organization->id, '--actor' => (string) $owner->id]));
    }

    public function test_requested_checks_pass_but_do_not_prove_overall_coverage_or_import_readiness(): void
    {
        $owner = User::factory()->create();
        $this->actingAsWithMfa($owner);
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->grantOwnerRead($organization, $owner);
        $person = Person::factory()->create(['organization_id' => $organization->id]);
        $path = $this->bundle($organization->id, [$this->item('person_id', $person->id, 'present'), $this->item('person_pseudonym', 'FICTIF_MISSING', 'absent')]);
        $this->assertSame(0, Artisan::call('data:references', ['manifest' => $path, 'checks' => 'checks.json', '--organization' => $organization->id, '--actor' => (string) $owner->id]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('requested_checks_passed', $report['status']);
        $this->assertSame('explicit_requests_only', $report['check_scope']);
        $this->assertFalse($report['coverage_verified']);
        $this->assertFalse($report['import_authorized']);
    }

    public function test_batch_lookup_does_not_issue_one_query_per_identifier(): void
    {
        $owner = User::factory()->create();
        $this->actingAsWithMfa($owner);
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->grantOwnerRead($organization, $owner);
        $person = Person::factory()->create(['organization_id' => $organization->id]);
        $context = app(DataPreparationContext::class)->resolve($organization->id, $owner->id);
        $checks = array_fill(0, 100, ['kind' => 'person_id', 'value' => $person->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(PeopleReferences::class)->lookup($context, $checks);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $peopleQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "people"'));
        $this->assertCount(1, $peopleQueries);
        $this->assertCount(100, $result);
        $this->assertNotContains(false, $result);
    }

    /** @return array<string,array{string,class-string}> */
    public static function otherDirectories(): array
    {
        return ['team' => ['team_id', DataPreparationReferences::class],
            'match' => ['match_id', \App\Modules\Competition\Application\Contracts\DataPreparationReferences::class],
            'contract' => ['contract_id', \App\Modules\Contracts\Application\Contracts\DataPreparationReferences::class],
            'metric' => ['metric_id', \App\Modules\Performance\Application\Contracts\DataPreparationReferences::class]];
    }

    /** @param class-string $directory */
    #[DataProvider('otherDirectories')]
    public function test_each_directory_reads_only_requested_identifiers_in_its_documented_scope(string $kind, string $directory): void
    {
        $owner = User::factory()->withMfa()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->grantOwnerRead($organization, $owner, ['sports.read', 'sports.statistics.read', 'administrative.read']);
        $game = GameCatalogueEntry::factory()->create();
        $other = Organization::factory()->create();
        $ids = [];
        foreach ([$organization, $other] as $tenant) {
            $person = Person::factory()->create(['organization_id' => $tenant->id]);
            $record = match ($kind) {
                'team_id' => Team::factory()->create(['organization_id' => $tenant->id, 'game_id' => $game->id]),
                'match_id' => MatchRecord::factory()->create(['organization_id' => $tenant->id, 'game_id' => $game->id, 'responsible_person_id' => $person->id]),
                'contract_id' => ContractRecord::factory()->create(['organization_id' => $tenant->id, 'responsible_person_id' => $person->id]),
                'metric_id' => MetricDefinition::query()->forceCreate(['id' => (string) Str::uuid(), 'game_id' => $game->id, 'code' => 'fictif-'.count($ids), 'created_by' => $owner->id]),
                default => throw new \InvalidArgumentException('Unknown fixture kind.'),
            };
            $ids[] = $record->id;
        }
        $context = app(DataPreparationContext::class)->resolve($organization->id, $owner->id);
        $result = app($directory)->lookup($context, [['kind' => $kind, 'value' => $ids[0]], ['kind' => $kind, 'value' => $ids[1]]]);
        $this->assertSame([true, $kind === 'metric_id'], array_values($result));
    }

    public function test_governance_rights_alone_and_read_permission_without_administration_are_insufficient(): void
    {
        $owner = User::factory()->withMfa()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $reader = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $reader, ['sports.read']);
        $path = $this->bundle($organization->id, [$this->item('person_pseudonym', 'FICTIF_MISSING', 'absent')]);
        foreach ([$owner, $reader] as $actor) {
            $this->assertSame(1, Artisan::call('data:references', ['manifest' => $path, 'checks' => 'checks.json', '--organization' => $organization->id, '--actor' => (string) $actor->id]));
            $this->assertStringContainsString(__('data_audit.invalid_review'), Artisan::output());
        }
    }

    /** @return array<string,array{string}> */
    public static function invalidChecks(): array
    {
        return ['stale digest' => ['digest'], 'foreign scope' => ['organization'], 'unknown kind' => ['kind'], 'unknown key' => ['unknown'],
            'unknown source' => ['source'], 'invalid position' => ['line'], 'invalid UUID' => ['uuid'], 'too many checks' => ['limit']];
    }

    #[DataProvider('invalidChecks')]
    public function test_invalid_request_is_refused_before_application_queries(string $variant): void
    {
        $organization = '10000000-0000-4000-8000-000000000001';
        $path = $this->bundle($organization, [$this->item('person_id', '20000000-0000-4000-8000-000000000002', 'present')]);
        $checks = json_decode(File::get(Storage::disk('reference-audit')->path('checks.json')), true, flags: JSON_THROW_ON_ERROR);
        match ($variant) {
            'digest' => $checks['plan_digest'] = str_repeat('0', 64),
            'organization' => $checks['organization_id'] = '20000000-0000-4000-8000-000000000002',
            'kind' => $checks['items'][0]['kind'] = 'PRIVATE_KIND',
            'unknown' => $checks['items'][0]['PRIVATE_KEY'] = 'PRIVATE_VALUE',
            'source' => $checks['items'][0]['source_id'] = 'UNKNOWN-SOURCE',
            'line' => $checks['items'][0]['line'] = 3,
            'uuid' => $checks['items'][0]['value'] = 'PRIVATE_BAD_UUID',
            'limit' => $checks['items'] = array_fill(0, 501, $checks['items'][0]),
            default => throw new \InvalidArgumentException('Unknown fixture variant.'),
        };
        Storage::disk('reference-audit')->put('checks.json', json_encode($checks, JSON_THROW_ON_ERROR));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame(1, Artisan::call('data:references', ['manifest' => $path, 'checks' => 'checks.json', '--organization' => $organization, '--actor' => '1']));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertStringNotContainsString('PRIVATE', Artisan::output());
    }

    public function test_another_tenant_owner_and_owner_without_mfa_cannot_use_database_check(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $other = User::factory()->create();
        $this->actingAsWithMfa($other);
        $path = $this->bundle($organization->id, [$this->item('person_pseudonym', 'FICTIF_MISSING', 'absent')]);
        foreach ([$other->id, $owner->id] as $actor) {
            $this->assertSame(1, Artisan::call('data:references', ['manifest' => $path, 'checks' => 'checks.json', '--organization' => $organization->id, '--actor' => (string) $actor]));
            $this->assertStringContainsString(__('data_audit.invalid_review'), Artisan::output());
            $this->assertStringNotContainsString($organization->id, Artisan::output());
        }
    }

    /** @return array<string,array{?string}> */
    public static function invalidActors(): array
    {
        return ['missing' => [null], 'zero' => ['0'], 'negative' => ['-1'], 'text' => ['PRIVATE_ACTOR'], 'overflow' => ['99999999999999999999999999']];
    }

    #[DataProvider('invalidActors')]
    public function test_console_rejects_invalid_actor_before_any_application_query(?string $actor): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame(1, Artisan::call('data:references', ['manifest' => 'PRIVATE_UNUSED.json', 'checks' => 'PRIVATE_UNUSED.json', '--organization' => '10000000-0000-4000-8000-000000000001', '--actor' => $actor]));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertStringNotContainsString('PRIVATE', Artisan::output());
    }

    public function test_pseudonym_query_binds_injection_like_text_as_a_value(): void
    {
        $owner = User::factory()->withMfa()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->grantOwnerRead($organization, $owner);
        Person::factory()->create(['organization_id' => $organization->id]);
        $context = app(DataPreparationContext::class)->resolve($organization->id, $owner->id);
        $result = app(PeopleReferences::class)->lookup($context, [['kind' => 'person_pseudonym', 'value' => "' OR 1=1 --"]]);
        $this->assertSame([false], $result);
        $this->assertDatabaseCount('people', 1);
    }

    /** @return array{source_id:string,line:int,kind:string,value:string,expect:string} */
    private function item(string $kind, string $value, string $expect): array
    {
        return ['source_id' => 'PEOPLE-A', 'line' => 1, 'kind' => $kind, 'value' => $value, 'expect' => $expect];
    }

    /** @param list<string> $permissions */
    private function grantOwnerRead(Organization $organization, User $owner, array $permissions = ['sports.read']): void
    {
        $role = OrganizationRole::factory()->create(['organization_id' => $organization->id, 'permissions' => $permissions, 'privileged' => count($permissions) > 1]);
        OrganizationRoleAssignment::factory()->create(['organization_id' => $organization->id, 'user_id' => $owner->id,
            'membership_id' => null, 'role_id' => $role->id, 'scope_id' => $organization->id]);
    }

    /** @param list<array{source_id:string,line:int,kind:string,value:string,expect:string}> $items */
    private function bundle(string $organization, array $items): string
    {
        Storage::fake('reference-audit');
        $manifest = json_decode(File::get(base_path('tests/Fixtures/data-preparation/clean.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $manifest['organization_id'] = $organization;
        $manifest['sources'][0]['organization_id'] = $organization;
        Storage::disk('reference-audit')->put('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        Storage::disk('reference-audit')->put('people-clean.csv', File::get(base_path('tests/Fixtures/data-preparation/people-clean.csv')));
        $path = Storage::disk('reference-audit')->path('manifest.json');
        $report = app(DataPreparationService::class)->audit($path, $organization)->data;
        Storage::disk('reference-audit')->put('checks.json', json_encode(['version' => 1, 'organization_id' => $organization, 'plan_digest' => $report['plan_digest'], 'items' => $items], JSON_THROW_ON_ERROR));

        return $path;
    }
}
