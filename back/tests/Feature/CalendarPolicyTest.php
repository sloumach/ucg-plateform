<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarPolicyTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool,bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false, false], 'reader' => [['calendar.read'], true, false], 'writer only' => [['calendar.write'], false, false],
            'editor' => [['calendar.read', 'calendar.write'], true, true], 'publisher only' => [['calendar.publish'], false, false],
            'sports' => [['sports.read', 'sports.write'], false, false], 'communications' => [['communication.read', 'communication.write'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_calendar_requires_explicit_permissions_and_matching_actor(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [CalendarActivity::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [CalendarActivity::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [CalendarActivity::class, $context]));
    }

    public function test_owner_and_technical_operator_have_no_implicit_business_access(): void
    {
        $org = Organization::factory()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', [CalendarActivity::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['calendar.read', 'calendar.write', 'calendar.publish']);
        PlatformRole::factory()->superadmin()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [CalendarActivity::class, $this->personContext($org, $user)]));
    }

    public function test_mfa_and_effective_assignment_are_required_for_calendar_reading(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['calendar.read']);
        $context = $this->personContext($org, $user);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [CalendarActivity::class, $context]));
        $user->forceFill(User::factory()->withMfa()->make()->only(['mfa_secret', 'mfa_confirmed_at', 'mfa_version']))->save();
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', [CalendarActivity::class, $context]));
        $grant->forceFill(['status' => 'revoked'])->save();
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [CalendarActivity::class, $context]));
    }
}
