<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchCorrectionPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,bool}> */
    public static function grants(): array
    {
        return ['none' => [[], false], 'writer' => [['sports.read', 'sports.write'], false], 'validator' => [['sports.read', 'sports.validate'], false], 'missing write' => [['sports.read', 'sports.correct'], false], 'missing read' => [['sports.write', 'sports.correct'], false], 'corrector' => [['sports.read', 'sports.write', 'sports.correct'], true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('grants')]
    public function test_only_explicit_permissions_allow_result_actions(array $permissions, bool $submit): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        foreach (['correctScore' => $submit] as $action => $expected) {
            $this->assertSame($expected, Gate::forUser($user)->allows($action, [MatchRecord::class, $context]));
            $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows($action, [MatchRecord::class, $context]));
        }
    }
}
