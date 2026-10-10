<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OrganizationPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_the_owner_can_view_and_update_an_organization(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertTrue(Gate::forUser($owner)->allows('viewAny', Organization::class));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $organization));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($other)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($other)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $organization));
        $this->assertFalse(Gate::forUser($owner)->allows('transferOwnership', $organization));
        $this->assertFalse(Gate::forUser($owner)->allows('transition', $organization));
    }
}
