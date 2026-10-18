<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ExternalCompetitionPolicyTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool,bool}> */
    public static function permissions(): array
    {
        return ['none' => [[], false, false], 'sports reader' => [['sports.read'], false, false], 'sports manager' => [['sports.read', 'sports.write'], false, false],
            'publisher' => [['sports.read', 'sports.publish'], false, false], 'missing base' => [['sports.competitions.read', 'sports.competitions.write'], false, false],
            'missing specific read' => [['sports.read', 'sports.competitions.write'], false, false], 'specific reader' => [['sports.read', 'sports.competitions.read'], true, false],
            'specific editor' => [['sports.read', 'sports.competitions.read', 'sports.competitions.write'], true, true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_only_explicit_complete_permissions_allow_private_competitions(array $permissions, bool $read, bool $write): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [ExternalCompetition::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [ExternalCompetition::class, $context]));
        $this->assertFalse(Gate::forUser($other)->allows('viewAny', [ExternalCompetition::class, $context]));
    }
}
