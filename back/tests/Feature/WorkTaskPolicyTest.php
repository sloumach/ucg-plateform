<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class WorkTaskPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false, false], 'reader' => [['tasks.read'], true, false], 'manage alone' => [['tasks.manage'], false, false],
            'manager' => [['tasks.read', 'tasks.manage'], true, true], 'communication alone' => [['communication.read', 'communication.write'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_permissions_and_actor_identity_are_required(array $permissions, bool $read, bool $manage): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [WorkTask::class, $context]));
        $this->assertSame($manage, Gate::forUser($user)->allows('manage', [WorkTask::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [WorkTask::class, $context]));
    }

    public function test_missing_mfa_and_suspended_membership_cannot_read_tasks(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['tasks.read', 'tasks.manage']);
        $context = $this->personContext($org, $user);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [WorkTask::class, $context]));
        $user->forceFill(User::factory()->withMfa()->make()->only(['mfa_secret', 'mfa_confirmed_at', 'mfa_version']))->save();
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', [WorkTask::class, $context]));
        OrganizationMembership::query()->whereKey($grant->membership_id)->update(['status' => 'suspended']);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [WorkTask::class, $context]));
    }
}
