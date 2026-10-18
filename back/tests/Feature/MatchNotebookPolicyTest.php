<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\MatchNotebookService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatches;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchNotebookPolicyTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMatches,ProvidesPeopleAccess;

    /** @return array<string,array{list<string>,list<string>}> */
    public static function grants(): array
    {
        return ['reader' => [['sports.read'], []], 'writer' => [['sports.read', 'sports.write'], []],
            'notes reader' => [['sports.read', 'sports.write', 'sports.notes.read'], []],
            'notes writer without read' => [['sports.read', 'sports.write', 'sports.notes.write'], []],
            'notes editor' => [['sports.read', 'sports.write', 'sports.notes.read', 'sports.notes.write'], ['internal']],
            'strategy editor' => [['sports.read', 'sports.write', 'sports.strategies.read', 'sports.strategies.write'], ['strategy']],
            'strategy writer without base' => [['sports.read', 'sports.strategies.read', 'sports.strategies.write'], []],
            'publisher' => [['sports.read', 'sports.write', 'sports.publish'], ['shared']],
            'validator' => [['sports.read', 'sports.validate'], []]];
    }

    /** @param list<string> $permissions
     * @param list<string> $audiences */
    #[DataProvider('grants')]
    public function test_only_explicit_complete_permissions_enable_notebook_writes(array $permissions, array $audiences): void
    {
        [$org,$writer,$reader,$person,$other,$game] = $this->matchScenario();
        $record = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $id = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $reader->id)->value('role_id');
        OrganizationRole::query()->where('id', $id)->firstOrFail()->forceFill(['permissions' => $permissions, 'privileged' => true])->save();
        $context = $this->personContext($org, $reader);
        $this->assertTrue(Gate::forUser($reader)->allows('viewAny', [MatchRecord::class, $context]));
        $this->assertSame(in_array('sports.write', $permissions, true), Gate::forUser($reader)->allows('manage', [MatchRecord::class, $context]));
        $result = app(MatchNotebookService::class)->index($context, $record['id'], []);
        $this->assertSame($audiences, $result['writable_audiences']);
        $this->assertFalse(Gate::forUser($writer)->allows('viewAny', [MatchRecord::class, $context]));
    }
}
