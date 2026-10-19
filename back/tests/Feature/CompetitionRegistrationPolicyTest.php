<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CompetitionRegistrationPolicyTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess;

    /**
     * @return array<string,array{list<string>,bool,bool}> */
    public static function permissions(): array
    {
        $read = ['sports.read', 'sports.competitions.read', 'sports.registrations.read'];

        return ['none' => [[], false, false], 'competition editor' => [['sports.read', 'sports.competitions.read', 'sports.competitions.write'], false, false],
            'missing base' => [['sports.registrations.read', 'sports.registrations.write'], false, false], 'missing registration read' => [['sports.read', 'sports.competitions.read', 'sports.registrations.write'], false, false],
            'reader' => [$read, true, false], 'writer' => [[...$read, 'sports.registrations.write'], true, true], 'broad sports writer' => [['sports.read', 'sports.write'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_only_complete_explicit_permissions_allow_registration_access(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [CompetitionRegistrationRevision::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [CompetitionRegistrationRevision::class, $context]));
        $this->assertFalse(Gate::forUser($other)->allows('viewAny', [CompetitionRegistrationRevision::class, $context]));
    }
}
