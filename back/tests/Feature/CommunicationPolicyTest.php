<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CommunicationPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool, bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false, false, false], 'reader' => [['communication.read'], true, false, false],
            'writer only' => [['communication.write'], false, false, false], 'commenter only' => [['communication.comment'], false, false, false],
            'publisher only' => [['communication.publish'], false, false, false],
            'editor' => [['communication.read', 'communication.write'], true, true, false],
            'participant' => [['communication.read', 'communication.comment'], true, false, true],
            'media' => [['media.read', 'media.write', 'media.publish'], false, false, false],
            'sport' => [['sports.read', 'sports.write'], false, false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_explicit_permissions_and_actor_identity_are_required(array $permissions, bool $read, bool $write, bool $comment): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        foreach (['viewAny' => $read, 'manage' => $write, 'comment' => $comment] as $ability => $expected) {
            $this->assertSame($expected, Gate::forUser($user)->allows($ability, [Announcement::class, $context]));
            $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows($ability, [Announcement::class, $context]));
        }
    }

    public function test_mfa_and_effective_membership_are_required_even_for_reading(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['communication.read', 'communication.write', 'communication.comment']);
        $context = $this->personContext($org, $user);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [Announcement::class, $context]));
        $user->forceFill(User::factory()->withMfa()->make()->only(['mfa_secret', 'mfa_confirmed_at', 'mfa_version']))->save();
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', [Announcement::class, $context]));
        OrganizationMembership::query()->whereKey($grant->membership_id)->update(['status' => 'suspended']);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [Announcement::class, $context]));
    }
}
