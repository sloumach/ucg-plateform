<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantContextPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_the_resolved_actor_can_view_the_context(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $context = new TenantContext((string) Str::uuid(), $actor->id, 'ucg', 'UTC', 'fr', (string) Str::uuid());
        $this->assertTrue(Gate::forUser($actor)->allows('view', $context));
        $this->assertFalse(Gate::forUser($other)->allows('view', $context));
        $this->assertFalse(Gate::allows('view', $context));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $context));
    }
}
