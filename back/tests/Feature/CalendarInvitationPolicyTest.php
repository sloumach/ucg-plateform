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

class CalendarInvitationPolicyTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool,bool}> */
    public static function permissions(): array
    {
        return ['none' => [[], false, false], 'reader' => [['calendar.read'], false, false],
            'writer' => [['calendar.read', 'calendar.write'], false, false],
            'manage alone' => [['calendar.invitations.manage'], false, false],
            'respond alone' => [['calendar.invitations.respond'], false, false],
            'manager without write' => [['calendar.read', 'calendar.invitations.manage'], false, false],
            'manager' => [['calendar.read', 'calendar.write', 'calendar.invitations.manage'], true, false],
            'respondent' => [['calendar.read', 'calendar.invitations.respond'], false, true],
            'all' => [['calendar.read', 'calendar.write', 'calendar.invitations.manage', 'calendar.invitations.respond'], true, true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_invitation_permissions_are_explicit_and_actor_must_match(array $permissions, bool $manage, bool $respond): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($manage, Gate::forUser($user)->allows('manageInvitations', [CalendarActivity::class, $context]));
        $this->assertSame($respond, Gate::forUser($user)->allows('respondInvitations', [CalendarActivity::class, $context]));
        $other = User::factory()->withMfa()->create();
        $this->assertFalse(Gate::forUser($other)->allows('manageInvitations', [CalendarActivity::class, $context]));
        $this->assertFalse(Gate::forUser($other)->allows('respondInvitations', [CalendarActivity::class, $context]));
    }

    public function test_owner_technical_accounts_missing_mfa_and_revoked_grants_do_not_bypass_permissions(): void
    {
        $org = Organization::factory()->create();
        $owner = User::query()->findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('manageInvitations', [CalendarActivity::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['calendar.read', 'calendar.write', 'calendar.invitations.manage', 'calendar.invitations.respond']);
        $context = $this->personContext($org, $user);
        $this->assertTrue(Gate::forUser($user)->allows('manageInvitations', [CalendarActivity::class, $context]));
        $grant->forceFill(['status' => 'revoked'])->save();
        $this->assertFalse(Gate::forUser($user)->allows('manageInvitations', [CalendarActivity::class, $context]));
        $grant->forceFill(['status' => 'active'])->save();
        $user->forceFill(['mfa_confirmed_at' => null])->save();
        $this->assertFalse(Gate::forUser($user)->allows('respondInvitations', [CalendarActivity::class, $context]));
        $user->forceFill(User::factory()->withMfa()->make()->only(['mfa_secret', 'mfa_confirmed_at', 'mfa_version']))->save();
        PlatformRole::factory()->superadmin()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('manageInvitations', [CalendarActivity::class, $context]));
        $this->assertFalse(Gate::forUser($user)->allows('respondInvitations', [CalendarActivity::class, $context]));
    }
}
