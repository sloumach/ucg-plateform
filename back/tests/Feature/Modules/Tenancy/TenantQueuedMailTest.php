<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Infrastructure\Mail\MailInvitationNotifier;
use App\Modules\Tenancy\Infrastructure\Mail\OrganizationInvitationMail;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantQueuedMailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_notifier_captures_inviter_identity_in_the_serialized_mail_context(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $organization->id, 'invited_by' => $organization->owner_user_id]);
        $requestId = (string) Str::uuid();
        Mail::fake();

        app(MailInvitationNotifier::class)->notify($invitation, $organization->name, $requestId);

        Mail::assertQueued(OrganizationInvitationMail::class, fn ($mail) => $mail->context->tenantId === $organization->id
            && $mail->context->actorUserId === $organization->owner_user_id && $mail->context->requestId === $requestId
            && $mail->hasTo($invitation->email) && $mail->afterCommit === true);
    }

    public function test_actual_queued_mail_restores_tenant_before_calling_the_mail_transport(): void
    {
        $organization = Organization::factory()->create();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $mail = new OrganizationInvitationMail($organization->name, 'https://example.test/', $context->toJobContext());
        $mail->onConnection('database')->to('recipient@example.test')->queue(app(QueueManager::class));
        $factory = $this->mock(Factory::class);
        $mailer = $this->mock(Mailer::class);
        $factory->shouldReceive('mailer')->once()->with(null)->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->withArgs(function ($view, array $data) use ($organization): bool {
            $this->assertSame($organization->id, app(TenantContext::class)->organizationId);

            return $data['organizationName'] === $organization->name;
        });

        $this->work();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_mail_queued_before_tenant_suspension_never_reaches_transport(): void
    {
        $organization = Organization::factory()->create();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        (new OrganizationInvitationMail($organization->name, 'https://example.test/', $context->toJobContext()))
            ->onConnection('database')->queue(app(QueueManager::class));
        DB::table('organizations')->where('id', $organization->id)->update(['status' => 'suspended']);
        $this->mock(Factory::class)->shouldNotReceive('mailer');

        $this->work();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_legacy_mail_without_actor_is_failed_closed_not_assumed_to_be_system_work(): void
    {
        $organization = Organization::factory()->create();
        (new OrganizationInvitationMail($organization->name, 'https://example.test/', new JobContext((string) Str::uuid(), $organization->id)))
            ->onConnection('database')->queue(app(QueueManager::class));
        $this->mock(Factory::class)->shouldNotReceive('mailer');

        $this->work();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_role_downgrade_after_queueing_refuses_invitation_delivery(): void
    {
        $membership = OrganizationMembership::factory()->administrator()->create();
        $context = app(TenantResolver::class)->resolve($membership->organization_id, '', $membership->user_id, (string) Str::uuid());
        (new OrganizationInvitationMail('Organisation', 'https://example.test/', $context->toJobContext()))
            ->onConnection('database')->queue(app(QueueManager::class));
        $membership->roles = ['member'];
        $membership->save();
        $this->mock(Factory::class)->shouldNotReceive('mailer');

        $this->work();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function work(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--sleep' => 0]));
    }
}
