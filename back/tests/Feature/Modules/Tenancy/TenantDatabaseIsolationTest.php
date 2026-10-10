<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationAccessEvent;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TenantDatabaseIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string}> */
    public static function auditActions(): array
    {
        return [
            'joining' => ['membership.joined'],
            'updating' => ['membership.updated'],
            'leaving' => ['membership.left'],
            'creating' => ['invitation.created'],
            'accepting' => ['invitation.accepted'],
            'declining' => ['invitation.declined'],
            'revoking' => ['invitation.revoked'],
            'expiring' => ['invitation.expired'],
        ];
    }

    #[DataProvider('auditActions')]
    public function test_database_accepts_an_audit_subject_from_the_same_organization(string $action): void
    {
        $subject = $this->subject($action);
        $event = $this->audit($subject->organization_id, $subject->id, $action);

        DB::table('organization_access_events')->insert($event);

        $this->assertDatabaseHas('organization_access_events', ['id' => $event['id'], 'organization_id' => $subject->organization_id, 'subject_id' => $subject->id]);
        $model = OrganizationAccessEvent::query()->whereKey($event['id'])->firstOrFail();
        $this->assertArrayNotHasKey('membership_id', $model->toArray());
        $this->assertArrayNotHasKey('invitation_id', $model->toArray());
    }

    #[DataProvider('auditActions')]
    public function test_database_rejects_an_audit_subject_from_another_existing_organization(string $action): void
    {
        $subject = $this->subject($action);
        $other = Organization::factory()->create();
        $event = $this->audit($other->id, $subject->id, $action);

        $this->assertRejected(fn () => DB::table('organization_access_events')->insert($event), '23503');
        $this->assertDatabaseCount('organization_access_events', 0);
        $this->assertModelExists($subject);
    }

    /** @return array<string, array{string}> */
    public static function subjectTypes(): array
    {
        return ['membership' => ['membership.joined'], 'invitation' => ['invitation.created']];
    }

    #[DataProvider('subjectTypes')]
    public function test_database_rejects_a_missing_subject(string $action): void
    {
        $organization = Organization::factory()->create();

        $this->assertRejected(fn () => DB::table('organization_access_events')
            ->insert($this->audit($organization->id, (string) Str::uuid(), $action)), '23503');
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    #[DataProvider('subjectTypes')]
    public function test_database_rejects_a_subject_of_the_wrong_type(string $action): void
    {
        $subject = $this->subject($action === 'membership.joined' ? 'invitation.created' : 'membership.joined');

        $this->assertRejected(fn () => DB::table('organization_access_events')->insert($this->audit($subject->organization_id, $subject->id, $action)), '23503');
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    public function test_unknown_actions_cannot_bypass_the_subject_foreign_keys(): void
    {
        $membership = OrganizationMembership::factory()->create();

        $this->assertRejected(fn () => DB::table('organization_access_events')->insert($this->audit($membership->organization_id, $membership->id, 'unrecognized')), '23514');
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    #[DataProvider('subjectTypes')]
    public function test_generated_subject_columns_cannot_be_overridden(string $action): void
    {
        $subject = $this->subject($action);
        $event = $this->audit($subject->organization_id, $subject->id, $action);
        $event['membership_id'] = null;
        $event['invitation_id'] = null;

        $this->assertRejected(fn () => DB::table('organization_access_events')->insert($event), '428C9');
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    #[DataProvider('subjectTypes')]
    public function test_audited_subjects_cannot_be_physically_deleted(string $action): void
    {
        $subject = $this->subject($action);
        $event = $this->audit($subject->organization_id, $subject->id, $action);
        DB::table('organization_access_events')->insert($event);

        $this->assertRejected(fn () => DB::table($subject->getTable())->where('id', $subject->id)->delete(), '23503');
        $this->assertModelExists($subject);
        $this->assertDatabaseHas('organization_access_events', ['id' => $event['id']]);
    }

    /** @return array<string, array{string}> */
    public static function auditMutations(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    #[DataProvider('auditMutations')]
    public function test_access_audits_remain_append_only_after_the_migration(string $operation): void
    {
        $subject = OrganizationMembership::factory()->create();
        $event = $this->audit($subject->organization_id, $subject->id, 'membership.joined');
        DB::table('organization_access_events')->insert($event);

        $this->assertRejected(function () use ($operation, $event): void {
            $query = DB::table('organization_access_events')->where('id', $event['id']);
            if ($operation === 'update') {
                $query->update(['subject_id' => (string) Str::uuid()]);
            } else {
                $query->delete();
            }
        }, '23514');
        $this->assertDatabaseHas('organization_access_events', ['id' => $event['id'], 'subject_id' => $subject->id]);
    }

    public function test_settings_cannot_be_transplanted_to_another_existing_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        DB::table('organization_settings')->where('organization_id', $second->id)->delete();

        $this->assertRejected(fn () => DB::table('organization_settings')->where('organization_id', $first->id)->update(['organization_id' => $second->id]), '23514');
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $first->id]);
        $this->assertDatabaseMissing('organization_settings', ['organization_id' => $second->id]);
    }

    /** @return array<string, array{string}> */
    public static function tenantTables(): array
    {
        return [
            'settings' => ['organization_settings'],
            'lifecycle' => ['organization_lifecycle_events'],
            'memberships' => ['organization_memberships'],
            'invitations' => ['organization_invitations'],
            'access' => ['organization_access_events'],
        ];
    }

    #[DataProvider('tenantTables')]
    public function test_database_rejects_null_organization_on_each_tenant_table(string $table): void
    {
        $subject = OrganizationMembership::factory()->create();
        $organization = Organization::query()->whereKey($subject->organization_id)->firstOrFail();
        $attributes = match ($table) {
            'organization_settings' => ['week_starts_on' => '1', 'date_format' => 'd/m/Y'],
            'organization_memberships' => $subject->getRawOriginal(),
            'organization_invitations' => OrganizationInvitation::factory()->create()->getRawOriginal(),
            'organization_lifecycle_events' => [
                'id' => (string) Str::uuid(), 'actor_user_id' => $organization->owner_user_id,
                'action' => 'created', 'to_status' => 'active', 'reason' => 'Test',
                'request_id' => (string) Str::uuid(), 'occurred_at' => '2026-10-10 12:00:00',
            ],
            default => $this->audit($subject->organization_id, $subject->id, 'membership.joined'),
        };
        $attributes['organization_id'] = null;
        if (isset($attributes['id'])) {
            $attributes['id'] = (string) Str::uuid();
        }

        $this->assertRejected(fn () => DB::table($table)->insert($attributes), '23502');
        $this->assertModelExists($organization);
    }

    public function test_membership_and_pending_invitation_uniqueness_is_local_to_each_organization(): void
    {
        $first = OrganizationMembership::factory()->create();
        $second = OrganizationMembership::factory()->create(['user_id' => $first->user_id]);
        OrganizationInvitation::factory()->create(['organization_id' => $first->organization_id, 'email' => 'same@example.test']);
        OrganizationInvitation::factory()->create(['organization_id' => $second->organization_id, 'email' => 'same@example.test']);

        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertDatabaseCount('organization_invitations', 2);
        $duplicateMembership = $first->getRawOriginal();
        $duplicateMembership['id'] = (string) Str::uuid();
        $this->assertRejected(fn () => DB::table('organization_memberships')->insert($duplicateMembership), '23505');
        $invitation = OrganizationInvitation::query()->where('organization_id', $first->organization_id)->firstOrFail()->getRawOriginal();
        $invitation['id'] = (string) Str::uuid();
        $this->assertRejected(fn () => DB::table('organization_invitations')->insert($invitation), '23505');
        $this->assertDatabaseCount('organization_memberships', 2);
        $this->assertDatabaseCount('organization_invitations', 2);
    }

    #[DataProvider('subjectTypes')]
    public function test_upgrade_and_rollback_preserve_populated_audit_payloads_and_guards(string $action): void
    {
        $migration = require database_path('migrations/2026_10_10_152734_enforce_tenant_database_isolation.php');
        $migration->down();
        $subject = $this->subject($action);
        $event = $this->audit($subject->organization_id, $subject->id, $action);
        DB::table('organization_access_events')->insert($event);
        $original = json_encode(DB::table('organization_access_events')->where('id', $event['id'])->select(array_keys($event))->first(), JSON_THROW_ON_ERROR);

        $migration->up();

        $this->assertSame($original, json_encode(DB::table('organization_access_events')->where('id', $event['id'])->select(array_keys($event))->first(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('organization_access_events', ['id' => $event['id'], 'subject_id' => $subject->id, 'request_id' => $event['request_id']]);
        $this->assertSame(['retained' => 'historical snapshot'], OrganizationAccessEvent::query()->whereKey($event['id'])->firstOrFail()->after);
        $this->assertRejected(fn () => DB::table('organization_access_events')->where('id', $event['id'])->delete());
        $migration->down();
        $this->assertSame($original, json_encode(DB::table('organization_access_events')->where('id', $event['id'])->select(array_keys($event))->first(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('organization_access_events', ['id' => $event['id'], 'subject_id' => $subject->id]);
        $this->assertRejected(fn () => DB::table('organization_access_events')->where('id', $event['id'])->update(['action' => 'rewritten']));
        $migration->up();
    }

    /** @return array<string, array{string}> */
    public static function invalidLegacyActions(): array
    {
        return ['membership' => ['membership.joined'], 'invitation' => ['invitation.created'], 'unknown action' => ['unrecognized']];
    }

    #[DataProvider('invalidLegacyActions')]
    public function test_upgrade_refuses_inconsistent_history_without_silently_repairing_it(string $action): void
    {
        $migration = require database_path('migrations/2026_10_10_152734_enforce_tenant_database_isolation.php');
        $migration->down();
        $subject = $this->subject($action);
        $other = Organization::factory()->create();
        $event = $this->audit($other->id, $subject->id, $action);
        DB::table('organization_access_events')->insert($event);

        try {
            $migration->up();
            $this->fail('Invalid legacy associations require an explicit remediation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('journal d’accès incohérent', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('organization_access_events', 'membership_id'));
            $this->assertDatabaseHas('organization_access_events', ['id' => $event['id'], 'organization_id' => $other->id, 'subject_id' => $subject->id]);
            $this->assertRejected(fn () => DB::table('organization_access_events')->where('id', $event['id'])->delete());
        }
    }

    private function subject(string $action): OrganizationMembership|OrganizationInvitation
    {
        return str_starts_with($action, 'membership.')
            ? OrganizationMembership::factory()->create()
            : OrganizationInvitation::factory()->create();
    }

    /** @return array<string, mixed> */
    private function audit(string $organizationId, string $subjectId, string $action): array
    {
        $organization = Organization::query()->whereKey($organizationId)->firstOrFail();

        return [
            'id' => (string) Str::uuid(), 'organization_id' => $organizationId,
            'actor_user_id' => $organization->owner_user_id, 'action' => $action, 'subject_id' => $subjectId,
            'before' => null, 'after' => json_encode(['retained' => 'historical snapshot'], JSON_THROW_ON_ERROR),
            'request_id' => (string) Str::uuid(), 'occurred_at' => '2026-10-10 12:00:00',
        ];
    }

    private function assertRejected(Closure $operation, ?string $postgresCode = null): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
            });
            $this->fail('The database must reject this invalid association or mutation.');
        } catch (QueryException $exception) {
            if ($postgresCode !== null && DB::getDriverName() === 'pgsql') {
                $this->assertSame($postgresCode, $exception->errorInfo[0] ?? null);
            } else {
                $this->addToAssertionCount(1);
            }
        }
    }
}
