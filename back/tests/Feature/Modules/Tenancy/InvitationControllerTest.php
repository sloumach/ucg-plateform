<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Contracts\InvitationNotifier;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Infrastructure\Mail\OrganizationInvitationMail;
use App\Support\Queue\JobContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InvitationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_invites_a_normalized_email_with_a_queued_mail_and_audit(): void
    {
        Mail::fake();
        $organization = Organization::factory()->create();
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->postJson('/api/v1/tenants/'.$organization->id.'/invitations', ['email' => ' NEW@example.test ', 'roles' => ['member']])
            ->assertCreated()->assertJsonPath('data.email', 'new@example.test')
            ->assertJsonPath('notification.type', 'success');
        $this->assertDatabaseCount('organization_invitations', 1);
        $this->assertDatabaseHas('organization_access_events', ['organization_id' => $organization->id, 'action' => 'invitation.created']);
        Mail::assertQueued(OrganizationInvitationMail::class, fn ($mail): bool => $mail->hasTo('new@example.test') && $mail->organizationName === $organization->name);
    }

    public function test_acceptance_joins_without_duplicating_the_account_and_cannot_be_replayed(): void
    {
        $recipient = User::factory()->create();
        $invitation = OrganizationInvitation::factory()->create(['email' => $recipient->email]);
        $this->actingAs($recipient)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('notification.type', 'success');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $invitation->organization_id, 'user_id' => $recipient->id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_access_events', ['action' => 'membership.joined', 'actor_user_id' => $recipient->id]);
        $this->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'INVITATION_ALREADY_CLOSED');
        $this->assertDatabaseCount('organization_memberships', 1);
    }

    public function test_decline_and_revocation_preserve_history_without_creating_memberships(): void
    {
        $recipient = User::factory()->create();
        $invitation = OrganizationInvitation::factory()->create(['email' => $recipient->email]);
        $this->actingAs($recipient)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'declined', 'confirm' => true])->assertOk();
        $other = OrganizationInvitation::factory()->create();
        $this->actingAs(User::query()->findOrFail($other->invited_by))
            ->deleteJson('/api/v1/tenants/'.$other->organization_id.'/invitations/'.$other->id)
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertDatabaseCount('organization_invitations', 2);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseHas('organization_access_events', ['action' => 'invitation.declined']);
        $this->assertDatabaseHas('organization_access_events', ['action' => 'invitation.revoked']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidResponses(): array
    {
        return [
            'explicit confirmation' => [['decision' => 'accepted'], 'confirm'],
            'unknown decision' => [['decision' => 'destroy', 'confirm' => true], 'decision'],
            'role injection' => [['decision' => 'accepted', 'confirm' => true, 'roles' => ['administrator']], 'roles'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidResponses')]
    public function test_response_payload_is_validated_before_any_membership_is_created(array $payload, string $field): void
    {
        $recipient = User::factory()->create();
        $invitation = OrganizationInvitation::factory()->create(['email' => $recipient->email]);
        $this->actingAs($recipient)->putJson('/api/v1/my-invitations/'.$invitation->id, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id, 'status' => 'pending']);
    }

    public function test_other_accounts_and_unverified_accounts_cannot_read_or_accept_the_invitation(): void
    {
        $invitation = OrganizationInvitation::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/v1/my-invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])->assertNotFound();
        $unverified = User::factory()->unverified()->create(['email' => $invitation->email]);
        $this->actingAs($unverified)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])->assertNotFound();
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    public function test_expired_invitation_and_ended_period_cannot_grant_access(): void
    {
        $recipient = User::factory()->create();
        $invitation = OrganizationInvitation::factory()->create(['email' => $recipient->email, 'expires_at' => now()->subMinute()]);
        $this->actingAs($recipient)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'INVITATION_EXPIRED');
        $invitation->forceFill(['expires_at' => now()->addDay(), 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()])->save();
        $this->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'INVITATION_PERIOD_ENDED');
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    public function test_duplicate_pending_invitation_is_rejected_but_expired_one_can_be_reissued(): void
    {
        Mail::fake();
        $invitation = OrganizationInvitation::factory()->create();
        $this->actingAs(User::query()->findOrFail($invitation->invited_by));
        $url = '/api/v1/tenants/'.$invitation->organization_id.'/invitations';
        $payload = ['email' => $invitation->email, 'roles' => ['member']];
        $this->postJson($url, $payload)->assertConflict()->assertJsonPath('code', 'INVITATION_ALREADY_PENDING');
        Mail::assertNothingQueued();
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->postJson($url, $payload)->assertCreated();
        $this->assertDatabaseCount('organization_invitations', 2);
        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id, 'status' => 'expired']);
        Mail::assertQueuedCount(1);
    }

    public function test_cross_tenant_revocation_returns_404_and_changes_nothing(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()->create();
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->deleteJson('/api/v1/tenants/'.$organization->id.'/invitations/'.$invitation->id)->assertNotFound();
        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id, 'status' => 'pending']);
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    public function test_inbox_is_filtered_paginated_and_loads_organization_names_without_n_plus_one(): void
    {
        $recipient = User::factory()->create();
        OrganizationInvitation::factory()->count(3)->create(['email' => $recipient->email]);
        OrganizationInvitation::factory()->create();
        DB::enableQueryLog();
        $this->actingAs($recipient)->getJson('/api/v1/my-invitations?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonStructure(['data' => [['organization_name']]]);
        $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], '"organizations"'));
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
    }

    public function test_existing_suspended_membership_cannot_be_bypassed_by_accepting_another_invitation(): void
    {
        $recipient = User::factory()->create();
        $membership = OrganizationMembership::factory()->suspended()->create(['user_id' => $recipient->id]);
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $membership->organization_id, 'email' => $recipient->email]);
        $this->actingAs($recipient)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'MEMBERSHIP_ALREADY_EXISTS');
        $this->assertDatabaseHas('organization_memberships', ['id' => $membership->id, 'status' => 'suspended']);
    }

    public function test_notification_preparation_failure_rolls_back_invitation_and_audit_with_a_safe_error(): void
    {
        $organization = Organization::factory()->create();
        $this->mock(InvitationNotifier::class)->shouldReceive('notify')->once()->andThrow(new RuntimeException('Private queue connection details'));
        $this->actingAs(User::query()->findOrFail($organization->owner_user_id))
            ->postJson('/api/v1/tenants/'.$organization->id.'/invitations', ['email' => 'new@example.test', 'roles' => ['member']])
            ->assertInternalServerError()->assertJsonMissing(['message' => 'Private queue connection details']);
        $this->assertDatabaseCount('organization_invitations', 0);
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    public function test_database_rejects_duplicate_pending_invitation_identity_change_and_invalid_period(): void
    {
        $invitation = OrganizationInvitation::factory()->create();
        $other = User::factory()->create();
        foreach ([
            fn () => OrganizationInvitation::factory()->create(['organization_id' => $invitation->organization_id, 'email' => $invitation->email]),
            fn () => DB::table('organization_invitations')->where('id', $invitation->id)->update(['invited_by' => $other->id]),
            fn () => DB::table('organization_invitations')->where('id', $invitation->id)->update(['ends_at' => now()->subYear()]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Invitation constraints must be enforced in the database.');
            } catch (QueryException) {
                $this->assertDatabaseCount('organization_invitations', 1);
                $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->id, 'invited_by' => $invitation->invited_by]);
            }
        }
    }

    public function test_inactive_organization_cannot_grant_new_membership_but_invitation_can_be_declined(): void
    {
        $recipient = User::factory()->create();
        $organization = Organization::factory()->suspended()->create();
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $organization->id, 'email' => $recipient->email]);
        $this->actingAs($recipient)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'ORGANIZATION_INACTIVE');
        $this->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'declined', 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('organization_memberships', 0);
    }

    public function test_offset_dates_are_stored_as_utc_instants_for_invitation_and_membership_updates(): void
    {
        Mail::fake();
        $recipient = User::factory()->create();
        $organization = Organization::factory()->create();
        $owner = User::query()->findOrFail($organization->owner_user_id);
        $invitationId = $this->actingAs($owner)->postJson('/api/v1/tenants/'.$organization->id.'/invitations',
            ['email' => $recipient->email, 'roles' => ['member'], 'starts_at' => '2030-01-01T15:30:45+02:00', 'ends_at' => '2030-01-02T15:30:45+02:00'])
            ->assertCreated()->json('data.id');
        $invitation = OrganizationInvitation::query()->whereKey($invitationId)->firstOrFail();
        $this->assertSame('2030-01-01 13:30:45', $invitation->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->actingAs($recipient)->putJson('/api/v1/my-invitations/'.$invitation->id, ['decision' => 'accepted', 'confirm' => true])->assertOk();
        $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $recipient->id)->firstOrFail();
        $this->assertSame('2030-01-01 13:30:45', $membership->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->getJson('/api/v1/tenants/'.$organization->id.'/context')->assertNotFound();
        $this->actingAs($owner)->putJson('/api/v1/tenants/'.$organization->id.'/memberships/'.$membership->id,
            ['roles' => ['member'], 'status' => 'active', 'starts_at' => '2031-01-01T15:30:45+02:00', 'ends_at' => '2031-01-02T15:30:45+02:00'])->assertOk();
        $this->assertSame('2031-01-01 13:30:45', $membership->refresh()->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_invitation_mail_escapes_organization_names(): void
    {
        $organization = Organization::factory()->create();
        $mail = new OrganizationInvitationMail('<script>alert(1)</script>', 'https://example.test/',
            new JobContext((string) Str::uuid(), $organization->id));
        $html = $mail->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
