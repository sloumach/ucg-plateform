<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AvailabilityPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool,bool,bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false, false, false], 'self' => [['availability.self'], true, true, false],
            'reader' => [['availability.read'], true, false, true], 'manager only' => [['availability.manage'], false, false, false],
            'manager' => [['availability.read', 'availability.manage'], true, true, true], 'self reader' => [['availability.self', 'availability.read'], true, true, true],
            'calendar' => [['calendar.read', 'calendar.write'], false, false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_explicit_permissions_and_actor_are_required(array $permissions, bool $read, bool $write, bool $people): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [Availability::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [Availability::class, $context]));
        $this->assertSame($people, Gate::forUser($user)->allows('people', [Availability::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [Availability::class, $context]));
    }

    public function test_owners_and_technical_operators_have_no_implicit_rights(): void
    {
        $org = Organization::factory()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', [Availability::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['availability.self', 'availability.read', 'availability.manage']);
        PlatformRole::factory()->superadmin()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('manage', [Availability::class, $this->personContext($org, $user)]));
    }

    public function test_mfa_membership_assignment_and_organization_must_remain_effective(): void
    {
        $this->travelTo('2026-10-17 12:00:00');
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['availability.self']);
        $context = $this->personContext($org, $user);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [Availability::class, $context]));
        $user->forceFill(User::factory()->withMfa()->make()->only(['mfa_secret', 'mfa_confirmed_at', 'mfa_version']))->save();
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', [Availability::class, $context]));
        $grant->forceFill(['ends_at' => now()->addHour()])->save();
        $this->travel(1)->hours();
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [Availability::class, $context]));
    }
}
