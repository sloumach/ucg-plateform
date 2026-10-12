<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonLifecyclePolicyTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool,bool,bool,bool,bool}> */
    public static function matrix(): array
    {
        $all = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'memberships.manage', 'invitations.manage'];
        $rows = [
            'ordinary' => [[], false, false, false, false, false],
            'sports reader' => [['sports.read'], true, false, false, false, false],
            'sports writer' => [['sports.write'], false, true, false, false, false],
            'admin reader' => [['administrative.read'], false, false, true, false, false],
            'admin writer' => [['administrative.write'], false, false, false, true, false],
            'full manager' => [$all, true, true, true, true, true],
        ];
        foreach (['sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage'] as $required) {
            $permissions = array_values(array_diff($all, [$required]));
            $rows['missing '.$required] = [$permissions, true, in_array('sports.write', $permissions, true), true, in_array('administrative.write', $permissions, true), false];
        }

        return $rows;
    }

    /** @param list<string> $permissions */
    #[DataProvider('matrix')]
    public function test_policy_uses_exact_permissions_and_context_actor(array $permissions, bool $readPeriods, bool $writePeriods, bool $readItems, bool $writeItems, bool $depart): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $gate = Gate::forUser($user);
        $this->assertSame($readPeriods, $gate->allows('periods', [PersonPeriod::class, $context]));
        $this->assertSame($writePeriods, $gate->allows('managePeriods', [PersonPeriod::class, $context]));
        $this->assertSame($readItems, $gate->allows('items', [PersonDepartureItem::class, $context]));
        $this->assertSame($writeItems, $gate->allows('manageItems', [PersonDepartureItem::class, $context]));
        $this->assertSame($depart, $gate->allows('depart', [PersonDeparture::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('depart', [PersonDeparture::class, $context]));
    }

    public function test_owner_without_business_grant_and_technical_actor_cannot_depart(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('depart', [PersonDeparture::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('depart', [PersonDeparture::class, $this->personContext($org, $user)]));
    }
}
