<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class SponsorshipAgreementPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, list<bool>}> */
    public static function grants(): array
    {
        return ['none' => [[], [false, false, false, false, false]],
            'reader' => [['partnerships.read'], [true, false, false, false, false]],
            'editor' => [['partnerships.read', 'partnerships.write'], [true, true, false, false, false]],
            'validator' => [['partnerships.read', 'partnerships.write', 'partnerships.validate'], [true, true, true, false, false]],
            'finance only' => [['financial.read', 'financial.write', 'financial.validate'], [false, false, false, false, false]],
            'finance reader' => [['partnerships.read', 'financial.read'], [true, false, false, true, false]],
            'finance writer' => [['partnerships.read', 'partnerships.write', 'financial.read', 'financial.write'], [true, true, false, true, true]],
            'HR only' => [['administrative.read', 'administrative.write', 'administrative.validate'], [false, false, false, false, false]]];
    }

    /** @param list<string> $permissions
     * @param list<bool> $expected */
    #[DataProvider('grants')]
    public function test_explicit_permission_matrix(array $permissions, array $expected): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        foreach (['viewAny', 'manage', 'validate', 'financialRead', 'financialWrite'] as $index => $ability) {
            $this->assertSame($expected[$index], Gate::forUser($user)->allows($ability, [SponsorshipAgreement::class, $context]), $ability);
        }
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('viewAny', [SponsorshipAgreement::class, $context]));
    }

    public function test_owner_and_technical_accounts_have_no_implicit_business_rights(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', [SponsorshipAgreement::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['partnerships.read', 'partnerships.write', 'partnerships.validate', 'financial.read']);
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('financialRead', [SponsorshipAgreement::class, $this->personContext($org, $user)]));
    }
}
