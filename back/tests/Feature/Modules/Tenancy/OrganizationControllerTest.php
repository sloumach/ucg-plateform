<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_401_without_authentication(): void
    {
        $this->getJson('/api/v1/organizations')->assertUnauthorized()->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');
    }

    public function test_lists_only_owned_organizations_with_bounded_pagination_and_no_n_plus_one(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->count(3)->create(['owner_user_id' => $owner->id]);
        Organization::factory()->create();
        DB::enableQueryLog();
        $response = $this->actingAs($owner)->getJson('/api/v1/organizations?per_page=2');
        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['meta' => ['request_id'], 'data' => [['settings' => ['week_starts_on', 'date_format']]]]);
        $organizationQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], '"organizations"') || str_contains($query['query'], '"organization_settings"'));
        DB::disableQueryLog();
        $this->assertCount(3, $organizationQueries);
    }

    public function test_returns_404_for_another_organizations_details_and_update(): void
    {
        $organization = Organization::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/v1/organizations/'.$organization->id)->assertNotFound();
        $this->putJson('/api/v1/organizations/'.$organization->id, $this->payload())->assertNotFound();
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => $organization->name]);
    }

    public function test_owner_can_read_and_update_settings_with_a_success_notification(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->actingAs($owner)->getJson('/api/v1/organizations/'.$organization->id)
            ->assertOk()->assertJsonPath('data.id', $organization->id);
        $this->putJson('/api/v1/organizations/'.$organization->id, $this->payload())
            ->assertOk()->assertJsonPath('data.name', 'Nom actualisé')
            ->assertJsonPath('notification.type', 'success')
            ->assertJsonPath('notification.message', 'Les paramètres de l’organisation ont été enregistrés.');
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => 'Nom actualisé', 'timezone' => 'Africa/Tunis', 'country' => 'TN']);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id, 'week_starts_on' => '7', 'date_format' => 'Y-m-d']);
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function invalidInputs(): array
    {
        return [
            'name required' => ['name', '', 'Ce champ est obligatoire.'],
            'name type' => ['name', ['invalid'], 'Ce champ doit être du texte.'],
            'name length' => ['name', str_repeat('a', 161), 'Ce champ ne peut pas dépasser 160 caractères.'],
            'timezone invalid' => ['timezone', 'GMT+2', 'Sélectionnez un fuseau horaire IANA valide.'],
            'language unsupported' => ['language', 'xx', 'La valeur sélectionnée n’est pas autorisée.'],
            'country invalid' => ['country', 'ZZ', 'La valeur sélectionnée n’est pas autorisée.'],
            'week invalid' => ['settings.week_starts_on', 8, 'Ce champ doit être compris entre 1 et 7.'],
            'week type' => ['settings.week_starts_on', 'monday', 'Ce champ doit être un nombre entier.'],
            'date format invalid' => ['settings.date_format', '<script>', 'La valeur sélectionnée n’est pas autorisée.'],
            'settings injection' => ['settings.extra', 'secret', 'Les paramètres doivent contenir uniquement les options autorisées.'],
            'slug immutable' => ['slug', 'another', 'Ce champ ne peut pas être modifié par cette opération.'],
            'owner immutable' => ['owner_user_id', 500, 'Ce champ ne peut pas être modifié par cette opération.'],
            'status protected' => ['status', 'archived', 'Ce champ ne peut pas être modifié par cette opération.'],
            'id immutable' => ['id', 'different', 'Ce champ ne peut pas être modifié par cette opération.'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_returns_422_for_invalid_or_protected_fields_without_mutation(string $field, mixed $value, string $message): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $payload = $this->payload();
        data_set($payload, $field, $value);
        $errorField = $field === 'settings.extra' ? 'settings' : $field;
        $response = $this->actingAs($owner)->putJson('/api/v1/organizations/'.$organization->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($errorField);
        $this->assertSame($message, $response->json('errors')[$errorField][0]);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => $organization->name, 'status' => 'active']);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id, 'week_starts_on' => '1']);
    }

    public function test_returns_409_for_ordinary_modifications_of_a_suspended_organization(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->suspended()->create(['owner_user_id' => $owner->id]);
        $this->actingAs($owner)->putJson('/api/v1/organizations/'.$organization->id, $this->payload())
            ->assertConflict()->assertJsonPath('code', 'ORGANIZATION_INACTIVE');
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => $organization->name, 'status' => 'suspended']);
    }

    public function test_returns_422_for_unbounded_pagination_or_owner_filter_injection(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->getJson('/api/v1/organizations?per_page=101&page=0&owner_user_id=99')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page', 'owner_user_id']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['name' => 'Nom actualisé', 'timezone' => 'Africa/Tunis', 'language' => 'fr', 'country' => 'TN',
            'settings' => ['week_starts_on' => 7, 'date_format' => 'Y-m-d']];
    }
}
