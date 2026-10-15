<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PartnershipPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false, false], 'reader' => [['partnerships.read'], true, false],
            'writer only' => [['partnerships.write'], false, false], 'editor' => [['partnerships.read', 'partnerships.write'], true, true],
            'hr' => [['administrative.read', 'administrative.write'], false, false],
            'finance' => [['financial.read', 'financial.write'], false, false], 'sport' => [['sports.read', 'teams.manage'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_explicit_permission_matrix(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [Sponsor::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [Sponsor::class, $context]));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [Sponsor::class, $context]));
    }

    public function test_platform_technical_roles_cannot_read_business_contacts_even_with_an_explicit_grant(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['partnerships.read', 'partnerships.write']);
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [Sponsor::class, $this->personContext($org, $user)]));
    }

    public function test_mfa_enrollment_is_required_and_suspension_removes_access(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $this->grantPeopleAccess($org, $user, ['partnerships.read', 'partnerships.write']);
        $context = $this->personContext($org, $user);
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', [Sponsor::class, $context]));
        $user->forceFill(['mfa_secret' => 'test-secret', 'mfa_confirmed_at' => now(), 'mfa_version' => (string) Str::uuid(), 'mfa_recovery_codes' => []])->save();
        $this->assertTrue(Gate::forUser($user)->allows('manage', [Sponsor::class, $context]));
        $org->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse(Gate::forUser($user)->allows('manage', [Sponsor::class, $context]));
    }
}
