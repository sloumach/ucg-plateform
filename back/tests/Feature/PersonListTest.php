<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Data\PersonListQuery;
use App\Modules\People\Application\Services\PersonService;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonListTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guest_and_member_without_permission_cannot_list_people(): void
    {
        $organization = Organization::factory()->create();
        $this->getJson($this->path($organization))->assertUnauthorized();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, []);
        $this->actingAsWithMfa($user);
        $this->getJson($this->path($organization))->assertForbidden();
    }

    public function test_list_is_paginated_and_stable_without_biographies_or_other_tenants(): void
    {
        [$organization] = $this->reader();
        $people = Person::factory()->count(3)->create(['organization_id' => $organization->id, 'pseudonym' => 'Same']);
        Person::factory()->create(['pseudonym' => 'SECRET_OTHER']);
        $expected = $people->pluck('id')->sort()->values()->all();
        $first = $this->getJson($this->path($organization).'?per_page=2&sort=pseudonym')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.game_profiles_count', 0);
        $second = $this->getJson($this->path($organization).'?per_page=2&page=2&sort=pseudonym')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringContainsString('sort=pseudonym', $first->json('links.next'));
        $this->assertSame($expected, [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')]);
        $this->assertSame(['id', 'version', 'pseudonym', 'language', 'timezone', 'status', 'game_profiles_count'], array_keys($first->json('data.0')));
        $this->assertSame($first->headers->get('X-Request-ID'), $first->json('meta.request_id'));
    }

    public function test_empty_and_out_of_range_pages_have_explicit_metadata(): void
    {
        [$organization] = $this->reader();
        $this->getJson($this->path($organization))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        Person::factory()->create(['organization_id' => $organization->id]);
        $this->getJson($this->path($organization).'?page=2')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 1);
    }

    public function test_search_escapes_wildcards_and_combines_status_and_language(): void
    {
        [$organization] = $this->reader();
        $person = Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'Ab%_!Hero', 'status' => 'active', 'language' => 'fr']);
        Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'AbXYZHero']);
        Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'Ab%_!Former', 'status' => 'former']);
        Person::factory()->create(['pseudonym' => 'Ab%_!Other']);
        $this->getJson($this->path($organization).'?'.http_build_query(['q' => 'ab%_!', 'status' => 'active', 'language' => 'fr']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $person->id);
        $this->getJson($this->path($organization).'?'.http_build_query(['q' => "ab' OR 1=1 --"]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_game_and_platform_filters_match_the_same_active_profile_and_do_not_duplicate_people(): void
    {
        [$organization] = $this->reader();
        $person = Person::factory()->create(['organization_id' => $organization->id]);
        GameProfile::factory()->count(2)->sequence(['region_key' => 'eu'], ['region_key' => 'na'])
            ->create(['organization_id' => $organization->id, 'person_id' => $person->id, 'game_key' => 'rocket-league', 'platform_key' => 'pc']);
        $split = Person::factory()->create(['organization_id' => $organization->id]);
        GameProfile::factory()->create(['organization_id' => $organization->id, 'person_id' => $split->id, 'game_key' => 'rocket-league', 'platform_key' => 'xbox']);
        GameProfile::factory()->create(['organization_id' => $organization->id, 'person_id' => $split->id, 'game_key' => 'valorant', 'platform_key' => 'pc']);
        $archived = Person::factory()->create(['organization_id' => $organization->id]);
        GameProfile::factory()->create(['organization_id' => $organization->id, 'person_id' => $archived->id, 'game_key' => 'rocket-league', 'platform_key' => 'pc', 'status' => 'archived']);
        GameProfile::factory()->create(['game_key' => 'rocket-league', 'platform_key' => 'pc']);
        $this->getJson($this->path($organization).'?game_key=rocket-league&platform_key=pc')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $person->id)->assertJsonPath('data.0.game_profiles_count', 2);
    }

    public function test_administrative_view_omits_sport_and_list_unneeded_private_columns(): void
    {
        [$organization] = $this->reader(['administrative.read']);
        Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id, 'first_name' => 'Private']);
        $response = $this->getJson($this->path($organization).'?view=administrative&account_linked=unlinked')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Private');
        $this->assertSame(['id', 'version', 'user_id', 'first_name', 'last_name', 'contact_email'], array_keys($response->json('data.0')));
    }

    public function test_account_link_filter_is_tenant_scoped_and_exact(): void
    {
        [$organization, $user] = $this->reader(['administrative.read']);
        $linked = Person::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
        Person::factory()->create(['organization_id' => $organization->id]);
        Person::factory()->create();
        $this->getJson($this->path($organization).'?account_linked=linked')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $linked->id)->assertJsonPath('data.0.user_id', $user->id);
    }

    public function test_foreign_organization_is_404_and_technical_actor_is_403(): void
    {
        [$organization, $user] = $this->reader();
        $other = Organization::factory()->create();
        $this->getJson($this->path($other))->assertNotFound();
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->getJson($this->path($organization))->assertForbidden();
    }

    public function test_privileged_read_without_mfa_returns_403(): void
    {
        [$organization] = $this->reader(['administrative.read']);
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->getJson($this->path($organization))->assertForbidden();
    }

    public function test_requested_view_limits_fields_even_with_both_permissions(): void
    {
        [$organization] = $this->reader(['sports.read', 'administrative.read']);
        Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id]);
        $sport = $this->getJson($this->path($organization).'?view=sports')->assertOk();
        $this->assertArrayNotHasKey('first_name', $sport->json('data.0'));
        $admin = $this->getJson($this->path($organization).'?view=administrative')->assertOk();
        $this->assertArrayNotHasKey('pseudonym', $admin->json('data.0'));
    }

    /** @return array<string, array{list<string>, string}> */
    public static function deniedFilters(): array
    {
        return [
            'prefix' => [['administrative.read'], 'q=ab'],
            'status' => [['administrative.read'], 'status=active'],
            'language' => [['administrative.read'], 'language=fr'],
            'game' => [['administrative.read'], 'game_key=valorant'],
            'platform' => [['administrative.read'], 'platform_key=pc'],
            'sort' => [['administrative.read'], 'sort=pseudonym'],
            'sports view' => [['administrative.read'], 'view=sports'],
            'linked state' => [['sports.read'], 'account_linked=linked'],
            'administrative view' => [['sports.read'], 'view=administrative'],
        ];
    }

    /** @param list<string> $permissions */
    #[DataProvider('deniedFilters')]
    public function test_unauthorized_filters_return_403_even_on_an_empty_list(array $permissions, string $query): void
    {
        [$organization] = $this->reader($permissions);
        $this->getJson($this->path($organization).'?'.$query)->assertForbidden();
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function invalidQueries(): array
    {
        return [
            'page zero' => ['page', 0, 'La valeur est trop courte ou inférieure à 1.'],
            'deep offset' => ['page', 10001, 'La limite autorisée de 10000 est dépassée.'],
            'size' => ['per_page', 101, 'La limite autorisée de 100 est dépassée.'],
            'size type' => ['per_page', 'one', 'Ce champ doit être un entier.'],
            'sort injection' => ['sort', 'id; DROP TABLE people', 'La valeur sélectionnée n’est pas autorisée.'],
            'direction injection' => ['direction', 'asc nulls last', 'La valeur sélectionnée n’est pas autorisée.'],
            'view' => ['view', 'financial', 'La valeur sélectionnée n’est pas autorisée.'],
            'prefix too short' => ['q', 'a', 'La valeur est trop courte ou inférieure à 2.'],
            'prefix too long' => ['q', str_repeat('a', 81), 'La limite autorisée de 80 est dépassée.'],
            'prefix array' => ['q', ['hello'], 'Ce champ doit être un texte.'],
            'status' => ['status', 'unknown', 'La valeur sélectionnée n’est pas autorisée.'],
            'game format' => ['game_key', 'bad key', 'Le format de ce champ est invalide.'],
            'platform length' => ['platform_key', str_repeat('x', 33), 'La limite autorisée de 32 est dépassée.'],
            'private search' => ['first_name', 'secret', 'Ce champ ne peut pas être fourni.'],
            'tenant injection' => ['organization_id', 'elsewhere', 'Ce champ ne peut pas être fourni.'],
            'blank prefix' => ['q', '', 'Ce champ est obligatoire.'],
            'page type' => ['page', 'one', 'Ce champ doit être un entier.'],
            'size zero' => ['per_page', 0, 'La valeur est trop courte ou inférieure à 1.'],
            'language' => ['language', 'xx', 'La valeur sélectionnée n’est pas autorisée.'],
            'linked state' => ['account_linked', 'yes', 'La valeur sélectionnée n’est pas autorisée.'],
            'game length' => ['game_key', str_repeat('a', 65), 'La limite autorisée de 64 est dépassée.'],
            'platform format' => ['platform_key', 'pc steam', 'Le format de ce champ est invalide.'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_returns_422_with_localized_message(string $key, mixed $value, string $message): void
    {
        [$organization] = $this->reader();
        $this->getJson($this->path($organization).'?'.http_build_query([$key => $value]))->assertUnprocessable()
            ->assertJsonValidationErrors($key)->assertJsonPath('errors.'.$key.'.0', $message);
    }

    public function test_multi_tenant_actor_receives_only_the_requested_organization(): void
    {
        [$organization, $user] = $this->reader();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read']);
        Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'Only Alpha']);
        Person::factory()->create(['organization_id' => $other->id, 'pseudonym' => 'Only Beta']);
        $this->getJson($this->path($organization))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pseudonym', 'Only Alpha');
        $this->getJson($this->path($other))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pseudonym', 'Only Beta');
    }

    public function test_service_revalidates_unknown_keys_before_querying(): void
    {
        [$organization, $user] = $this->reader();
        $this->expectException(ValidationException::class);
        app(PersonService::class)->paginate($this->personContext($organization, $user), new PersonListQuery(['sort' => 'id', 'first_name' => 'private']));
    }

    /** @return array<string, array{string}> */
    public static function cacheStores(): array
    {
        return ['memory' => ['array'], 'database' => ['database']];
    }

    #[DataProvider('cacheStores')]
    public function test_query_count_is_constant_with_one_or_twenty_one_people(string $cache): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => $cache]);
        [$organization] = $this->reader(['sports.read', 'administrative.read']);
        Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id]);
        $path = $this->path($organization).'?per_page=21';
        $this->getJson($path)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $small = count(DB::getQueryLog());
        Person::factory()->count(20)->create(['organization_id' => $organization->id]);
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(21, 'data');
        $this->assertSame($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /** @param list<string> $permissions
     * @return array{Organization, User}
     */
    private function reader(array $permissions = ['sports.read']): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, $permissions);
        $this->actingAsWithMfa($user);

        return [$organization, $user];
    }

    private function path(Organization $organization): string
    {
        return '/api/v1/tenants/'.$organization->id.'/people';
    }
}
