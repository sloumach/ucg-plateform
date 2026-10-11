<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\IdentityAuditEvent;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IdentityAuditEvent> */
final class IdentityAuditEventFactory extends Factory
{
    protected $model = IdentityAuditEvent::class;

    public function definition(): array
    {
        return ['actor_user_id' => User::factory()->withMfa(), 'action' => 'domain.changed', 'target_type' => 'journal',
            'target_id' => 'platform', 'reason_code' => 'domain_change', 'before' => (object) [], 'after' => (object) ['active' => true],
            'request_id' => (string) Str::uuid(), 'ip_address' => '127.0.0.1', 'occurred_at' => now()];
    }
}
