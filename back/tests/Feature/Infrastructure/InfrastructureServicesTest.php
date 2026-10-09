<?php

namespace Tests\Feature\Infrastructure;

use App\Contracts\Storage\ArtifactStorage;
use App\Modules\Identity\Domain\Models\User;
use App\Support\Queue\JobContext;
use App\Support\Queue\QueueName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Fixtures\Events\TenantPrivateEvent;
use Tests\Fixtures\Jobs\StoreTenantArtifact;
use Tests\TestCase;

class InfrastructureServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_job_persists_an_artifact_with_explicit_tenant_context(): void
    {
        config(['filesystems.artifacts' => 'artifact-tests']);
        Storage::fake('artifact-tests');
        $context = new JobContext('request-123', 'tenant-456');
        $job = new StoreTenantArtifact($context, 'checks/status.json', '{"ready":true}');

        $job->handle($this->app->make(ArtifactStorage::class));

        $disk = Storage::disk('artifact-tests');
        $disk->assertExists('tenants/tenant-456/checks/status.json');
        $this->assertSame('{"ready":true}', $disk->get('tenants/tenant-456/checks/status.json'));
        $this->assertSame(QueueName::Default->value, $job->queue);
        $this->assertSame(
            ['request:request-123', 'tenant:tenant-456'],
            $job->tags(),
        );
    }

    public function test_artifact_storage_rejects_path_traversal(): void
    {
        config(['filesystems.artifacts' => 'artifact-tests']);
        Storage::fake('artifact-tests');
        $storage = $this->app->make(ArtifactStorage::class);

        $this->expectException(InvalidArgumentException::class);

        $storage->putForTenant('tenant-456', '../outside.json', '{}');
    }

    public function test_private_event_preserves_context_and_uses_the_broadcast_queue(): void
    {
        $event = new TenantPrivateEvent(
            new JobContext('request-123', 'tenant-456'),
            userId: 42,
        );

        $this->assertSame('private-users.42', $event->broadcastOn()[0]->name);
        $this->assertSame(QueueName::Broadcasts->value, $event->broadcastQueue());
        $this->assertSame('tenant.infrastructure.verified', $event->broadcastAs());
        $this->assertSame([
            'tenant_id' => 'tenant-456',
            'request_id' => 'request-123',
        ], $event->broadcastWith());
    }

    public function test_authenticated_user_can_authorize_their_private_channel(): void
    {
        $user = User::factory()->create([
            'email' => 'channel-owner@ucg.test',
            'password' => 'valid-password',
        ]);
        $this->authenticateSpa($user);

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$user->getKey(),
            ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_authenticated_user_cannot_authorize_another_users_private_channel(): void
    {
        $user = User::factory()->create([
            'email' => 'channel-member@ucg.test',
            'password' => 'valid-password',
        ]);
        $otherUser = User::factory()->create();
        $this->authenticateSpa($user);

        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$otherUser->getKey(),
            ])
            ->assertForbidden();
    }

    public function test_horizon_dashboard_is_denied_without_an_explicit_allowlist(): void
    {
        $user = User::factory()->create(['email' => 'operator@ucg.test']);
        config(['horizon.authorized_emails' => []]);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_horizon_dashboard_allows_an_allowlisted_operator(): void
    {
        $user = User::factory()->create(['email' => 'operator@ucg.test']);
        config(['horizon.authorized_emails' => ['operator@ucg.test']]);

        $this->assertTrue(Gate::forUser($user)->allows('viewHorizon'));
    }

    private function authenticateSpa(User $user): void
    {
        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'valid-password',
            ])
            ->assertOk();
    }

    /** @return array<string, string> */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
