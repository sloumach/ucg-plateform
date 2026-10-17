<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool,bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false, false], 'read' => [['sports.read'], true, false],
            'write without read' => [['sports.write'], false, false], 'editor' => [['sports.read', 'sports.write'], true, true],
            'unrelated calendar' => [['calendar.read', 'calendar.write'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_policy_requires_the_explicit_sporting_permissions(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [MatchRecord::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [MatchRecord::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('manage', [MatchRecord::class, $context]));
    }

    public function test_owner_technical_account_and_revoked_assignment_grant_no_implicit_access(): void
    {
        $org = Organization::factory()->create();
        $owner = User::query()->findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', [MatchRecord::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['sports.read', 'sports.write']);
        $context = $this->personContext($org, $user);
        $grant->forceFill(['status' => 'revoked'])->save();
        $this->assertFalse(Gate::forUser($user)->allows('manage', [MatchRecord::class, $context]));
        $grant->forceFill(['status' => 'active'])->save();
        PlatformRole::factory()->superadmin()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('manage', [MatchRecord::class, $context]));
    }
}
