<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Validation\PersonImportInput;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonImportPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_technical_platform_actor_cannot_import_even_with_business_grants(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, PersonImportInput::PERMISSIONS);
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('manage', [PersonImport::class, $this->personContext($org, $user)]));
    }

    /** @return array<string,array{list<string>,bool}> */
    public static function permissions(): array
    {
        $cases = ['complete' => [PersonImportInput::PERMISSIONS, true], 'empty' => [[], false]];
        foreach (PersonImportInput::PERMISSIONS as $permission) {
            $cases[$permission.' missing'] = [array_values(array_diff(PersonImportInput::PERMISSIONS, [$permission])), false];
        }

        return $cases;
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_all_explicit_read_and_write_permissions_are_required(array $permissions, bool $allowed): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->assertSame($allowed, Gate::forUser($user)->allows('manage', [PersonImport::class, $this->personContext($org, $user)]));
    }

    public function test_context_actor_mismatch_and_owner_without_business_grants_are_refused(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('manage', [PersonImport::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, PersonImportInput::PERMISSIONS);
        $this->assertFalse(Gate::forUser($owner)->allows('manage', [PersonImport::class, $this->personContext($org, $user)]));
    }
}
