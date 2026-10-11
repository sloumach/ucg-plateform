<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationAuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationAuditEvent> */
final class OrganizationAuditEventFactory extends Factory
{
    protected $model = OrganizationAuditEvent::class;

    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'actor_user_id' => User::factory()->withMfa(), 'action' => 'domain.changed', 'target_type' => 'journal',
            'target_id' => 'tenant', 'reason_code' => 'domain_change', 'before' => (object) [], 'after' => (object) ['active' => true],
            'request_id' => (string) Str::uuid(), 'ip_address' => '127.0.0.1', 'occurred_at' => now()];
    }
}
