<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Support\Audit\AuditState;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class TeamLineupPolicyTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesLineups;

    /** @return array<string,array{list<string>,bool,bool}> */
    public static function permissions(): array
    {
        return ['none' => [[], false, false], 'read' => [['sports.read'], true, false], 'manage alone' => [['teams.manage'], false, false],
            'both' => [['sports.read', 'teams.manage'], true, true], 'sports write only' => [['sports.write'], false, false], 'administrative' => [['administrative.read', 'administrative.write'], false, false]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_policy_requires_explicit_organization_sporting_read_and_management(array $permissions, bool $read, bool $write): void
    {
        [$org,$user] = $this->lineupEditor($permissions);
        $context = $this->personContext($org, $user);
        $this->assertSame($read, Gate::forUser($user)->allows('viewAny', [TeamLineup::class, $context]));
        $this->assertSame($write, Gate::forUser($user)->allows('manage', [TeamLineup::class, $context]));
        $other = User::factory()->create();
        $this->assertFalse(Gate::forUser($other)->allows('manage', [TeamLineup::class, $context]));
    }

    public function test_audit_rejects_unbounded_or_free_text_snapshot_projection(): void
    {
        $this->expectException(ValidationException::class);
        AuditState::project(['team_lineup_membership_ids' => ['private free text']], true);
    }
}
