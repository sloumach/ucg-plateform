<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\ProvidesLineups;
use Tests\TestCase;

class TeamEligibilityPolicyTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesLineups;

    public function test_sports_read_and_explicit_manage_are_required_for_the_context_actor(): void
    {
        [$org,$reader] = $this->lineupEditor(['sports.read']);
        $ctx = $this->personContext($org, $reader);
        $this->assertTrue(Gate::forUser($reader)->allows('viewAny', [TeamEligibilityRuleset::class, $ctx]));
        $this->assertFalse(Gate::forUser($reader)->allows('manage', [TeamEligibilityRuleset::class, $ctx]));
        $other = User::factory()->create();
        $this->assertFalse(Gate::forUser($other)->allows('viewAny', [TeamEligibilityRuleset::class, $ctx]));
    }
}
