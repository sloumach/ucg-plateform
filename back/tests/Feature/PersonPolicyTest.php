<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool, bool}> */
    public static function permissions(): array
    {
        return [
            'ordinary member' => [[], false, false, false],
            'sports reader' => [['sports.read'], true, false, false],
            'sports writer' => [['sports.write'], false, true, true],
            'administrative reader' => [['administrative.read'], true, false, false],
            'administrative writer' => [['administrative.write'], false, false, true],
            'sports editor' => [['sports.read', 'sports.write'], true, true, true],
        ];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_policy_uses_explicit_permissions_and_matches_context_actor(array $permissions, bool $read, bool $create, bool $update): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, $permissions);
        $context = $this->personContext($organization, $user);
        $gate = Gate::forUser($user);

        $this->assertSame($read, $gate->allows('view', [Person::class, $context]));
        $this->assertSame($create, $gate->allows('create', [Person::class, $context]));
        $this->assertSame($update, $gate->allows('update', [Person::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('view', [Person::class, $context]));
    }

    public function test_owner_and_administrator_do_not_receive_implicit_person_permissions(): void
    {
        $organization = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($organization->owner_user_id);
        $user = User::factory()->withMfa()->create();
        $grant = $this->grantPeopleAccess($organization, $user, []);
        OrganizationMembership::query()->whereKey($grant->membership_id)->update(['roles' => ['administrator']]);

        foreach ([$owner, $user] as $actor) {
            foreach (['view', 'create', 'update'] as $ability) {
                $this->assertFalse(Gate::forUser($actor)->allows($ability, [Person::class, $this->personContext($organization, $actor)]));
            }
        }
    }

    public function test_technical_role_blocks_business_access_even_with_an_effective_grant(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        PlatformRole::factory()->create(['user_id' => $user->id]);

        foreach (['view', 'create', 'update'] as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, [Person::class, $this->personContext($organization, $user)]));
        }
    }
}
