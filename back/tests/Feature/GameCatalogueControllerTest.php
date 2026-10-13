<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class GameCatalogueControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase;

    public function test_guest_cannot_read_or_write_catalogue(): void
    {
        $this->getJson('/api/v1/game-catalogue')->assertUnauthorized();
        $this->postJson('/api/v1/game-catalogue', $this->payload())->assertUnauthorized();
    }

    public function test_verified_member_can_read_bounded_global_metadata_without_tenant_data(): void
    {
        $this->actingAsWithMfa(User::factory()->create());
        $response = $this->getJson('/api/v1/game-catalogue?per_page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.can_manage', false)
            ->assertJsonMissingPath('data.0.organization_id');
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('meta.request_id'));
        $this->assertTrue(Str::isUuid($response->json('meta.catalogue_version')));
    }

    public function test_superadmin_creates_global_game_without_activating_it_for_any_organization(): void
    {
        $this->operator();
        $response = $this->postJson('/api/v1/game-catalogue', $this->payload())->assertCreated()
            ->assertJsonPath('data.key', 'new-game')->assertJsonPath('meta.catalogue_version', DB::table('game_catalogue_state')->value('version'))
            ->assertJsonPath('notification.message', 'Le catalogue commun a été enregistré.');
        $this->assertDatabaseHas('game_catalogue_entries', ['id' => $response->json('data.id'), 'default_enabled' => false]);
        $this->assertDatabaseCount('organization_games', 0);
        $this->assertDatabaseCount('game_catalogue_events', 1);
        $event = DB::table('game_catalogue_events')->first();
        $this->assertNotNull($event);
        $this->assertSame($response->json('meta.request_id'), $event->request_id);
        $this->assertStringNotContainsString('tenant', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_existing_game_accepts_shared_provider_on_several_platforms(): void
    {
        $this->operator();
        $game = GameCatalogueEntry::query()->where('key', 'valorant')->where('kind', 'game')->firstOrFail();
        $this->patchJson('/api/v1/game-catalogue/'.$game->id, ['name' => 'VALORANT',
            'active' => true, 'definition' => $game->definition, 'version' => $game->version,
            'catalogue_version' => $this->revision(), 'confirm_global' => true])->assertOk();
        $this->assertDatabaseCount('game_catalogue_events', 1);
    }

    /** @return array<string, array{string}> */
    public static function scopedKinds(): array
    {
        return ['mode' => ['mode'], 'role' => ['role'], 'map' => ['map'], 'release' => ['release']];
    }

    #[DataProvider('scopedKinds')]
    public function test_scoped_reference_is_created_and_listed_under_its_game(string $kind): void
    {
        $this->operator();
        $game = GameCatalogueEntry::query()->where('kind', 'game')->firstOrFail();
        $response = $this->postJson('/api/v1/game-catalogue', ['kind' => $kind, 'game_id' => $game->id,
            'key' => 'reference-test', 'name' => 'Référence de test', 'active' => true,
            'catalogue_version' => $this->revision(), 'confirm_global' => true])->assertCreated()->assertJsonPath('data.game_id', $game->id);
        $this->getJson('/api/v1/game-catalogue?kind='.$kind.'&game_id='.$game->id)->assertOk()->assertJsonPath('data.0.id', $response->json('data.id'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'global confirmation' => [['confirm_global' => false], 'confirm_global'],
            'tenant injection' => [['organization_id' => 'spoof'], 'organization_id'],
            'permissions injection' => [['permissions' => ['*']], 'permissions'],
            'key format' => [['key' => 'Bad Key'], 'key'],
            'unknown kind' => [['kind' => 'team'], 'kind'],
            'unknown platform' => [['definition' => ['platforms' => ['mobile' => []], 'regions' => ['eu']]], 'definition.platforms'],
            'unknown provider' => [['definition' => ['platforms' => ['pc' => ['unknown']], 'regions' => ['eu']]], 'definition.platforms'],
            'provider duplicate' => [['definition' => ['platforms' => ['pc' => ['riot', 'riot']], 'regions' => ['eu']]], 'definition.platforms'],
            'unknown region' => [['definition' => ['platforms' => ['pc' => []], 'regions' => ['unknown']]], 'definition.regions'],
            'definition injection' => [['definition' => ['platforms' => [], 'regions' => [], 'secret' => 'x']], 'definition'],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_global_payload_is_rejected_without_audit_or_version_change(array $change, string $field): void
    {
        $this->operator();
        $revision = $this->revision();
        $this->postJson('/api/v1/game-catalogue', [...$this->payload(), ...$change])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($revision, $this->revision());
        $this->assertDatabaseCount('game_catalogue_entries', 18);
        $this->assertDatabaseCount('game_catalogue_events', 0);
    }

    public function test_duplicates_and_stale_versions_never_overwrite_catalogue(): void
    {
        $this->operator();
        $payload = $this->payload();
        $created = $this->postJson('/api/v1/game-catalogue', $payload)->assertCreated();
        $this->postJson('/api/v1/game-catalogue', $payload)->assertConflict()->assertJsonPath('code', 'GAME_CATALOGUE_VERSION_STALE');
        $this->postJson('/api/v1/game-catalogue', [...$payload, 'catalogue_version' => $this->revision()])->assertConflict()->assertJsonPath('code', 'GAME_CATALOGUE_ALREADY_EXISTS');
        $this->patchJson('/api/v1/game-catalogue/'.$created->json('data.id'), [
            'name' => 'Overwrite', 'active' => false, 'definition' => $created->json('data.definition'),
            'version' => (string) Str::uuid(), 'catalogue_version' => $this->revision(), 'confirm_global' => true])->assertConflict();
        $this->assertDatabaseCount('game_catalogue_events', 1);
        $this->assertDatabaseHas('game_catalogue_entries', ['id' => $created->json('data.id'), 'name' => 'Nouveau jeu', 'active' => true]);
    }

    public function test_global_keys_scope_and_defaults_cannot_be_changed_or_deleted(): void
    {
        $this->operator();
        $entry = GameCatalogueEntry::query()->where('kind', 'platform')->firstOrFail();
        $this->patchJson('/api/v1/game-catalogue/'.$entry->id, ['name' => 'Nom', 'active' => false,
            'version' => $entry->version, 'catalogue_version' => $this->revision(), 'confirm_global' => true,
            'key' => 'new-key'])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->deleteJson('/api/v1/game-catalogue/'.$entry->id)->assertStatus(405);
        $this->assertDatabaseCount('game_catalogue_events', 0);
    }

    public function test_scoped_and_global_kinds_reject_wrong_parent_and_excessive_pages(): void
    {
        $this->operator();
        $this->postJson('/api/v1/game-catalogue', [...$this->payload(), 'kind' => 'map'])->assertUnprocessable()->assertJsonValidationErrors('game_id');
        $platform = GameCatalogueEntry::query()->where('kind', 'platform')->firstOrFail();
        $this->postJson('/api/v1/game-catalogue', [...$this->payload(), 'game_id' => $platform->id])->assertUnprocessable();
        $this->getJson('/api/v1/game-catalogue?kind=map')->assertUnprocessable();
        $this->getJson('/api/v1/game-catalogue?per_page=101')->assertUnprocessable();
        $this->assertDatabaseCount('game_catalogue_events', 0);
    }

    private function operator(): User
    {
        $user = User::factory()->withMfa()->create();
        app(PlatformRoleService::class)->configure($user->id, PlatformRoleType::Superadmin, true, null, 'Initialisation de test',
            (string) Str::uuid(), true);
        $this->actingAsWithMfa($user);

        return $user;
    }

    private function revision(): string
    {
        return DB::table('game_catalogue_state')->value('version');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['kind' => 'game', 'key' => 'new-game', 'name' => 'Nouveau jeu', 'active' => true,
            'definition' => ['platforms' => ['pc' => ['steam']], 'regions' => ['eu']],
            'catalogue_version' => $this->revision(), 'confirm_global' => true];
    }
}
