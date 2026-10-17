<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\CalendarChange;
use App\Modules\Scheduling\Domain\Models\CalendarChangeRecipient;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCalendarChanges;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarChangeWorkerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesCalendarChanges, ProvidesPeopleAccess;

    public function test_retries_deliver_each_change_once_and_history_remains_ordered_after_later_changes(): void
    {
        [$org, $writer, $reader, , $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertOk();
        $activity->refresh();
        $this->travel(1)->seconds();
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'archive' => true, 'change_reason' => 'Annulation définitive du rendez-vous.'])->assertOk();
        $this->processChanges($org);
        $first = DB::table('calendar_change_recipients')->orderBy('id')->get(['id', 'delivered_at', 'run_id'])->map(fn (object $n): array => (array) $n)->all();
        $this->processChanges($org);
        $this->assertSame($first, DB::table('calendar_change_recipients')->orderBy('id')->get(['id', 'delivered_at', 'run_id'])->map(fn (object $n): array => (array) $n)->all());
        $this->assertSame(2, CalendarChangeRecipient::query()->whereNotNull('delivered_at')->count());
        $this->actingAsWithMfa($reader);
        $this->getJson($this->changeRoot($org).'/changes?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson($this->changeRoot($org).'/changes?per_page=1&page=2')->assertJsonPath('data.0.change.kind', 'rescheduled')->assertJsonPath('data.0.current', false);
        $this->actingAsWithMfa($writer);
        $this->getJson($this->changePath($org, $activity).'/changes')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.delivered_count', 1);
    }

    /** @return array<string,array{string}> */
    public static function unavailableRecipients(): array
    {
        $changes = ['grant', 'membership', 'mfa', 'email', 'technical', 'person', 'rebound'];

        return array_combine($changes, array_map(fn (string $key): array => [$key], $changes));
    }

    #[DataProvider('unavailableRecipients')]
    public function test_recipient_eligibility_is_rechecked_before_delivery(string $change): void
    {
        [$org, , $reader, $person, $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertOk();
        match ($change) {
            'grant' => OrganizationRoleAssignment::query()->where('user_id', $reader->id)->update(['status' => 'revoked']),
            'membership' => OrganizationMembership::query()->where('user_id', $reader->id)->update(['status' => 'suspended']),
            'mfa' => $reader->forceFill(['mfa_confirmed_at' => null])->save(),
            'email' => $reader->forceFill(['email_verified_at' => null])->save(),
            'technical' => PlatformRole::factory()->superadmin()->create(['user_id' => $reader->id]),
            'person' => $person->forceFill(['status' => 'former'])->save(),
            'rebound' => $person->forceFill(['user_id' => User::factory()->withMfa()->create()->id])->save(),
            default => throw new \InvalidArgumentException('Unknown eligibility change.'),
        };
        $this->processChanges($org);
        $this->assertDatabaseHas('calendar_change_recipients', ['recipient_id' => $reader->id, 'delivered_at' => null, 'blocked_reason' => 'recipient_unavailable']);
        $this->assertDatabaseCount('calendar_changes', 1);
    }

    public function test_departed_issuer_blocks_delivery_and_restored_permissions_allow_one_later_delivery(): void
    {
        [$org, $writer, $reader, , $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertOk();
        $grant = OrganizationRoleAssignment::query()->where('user_id', $writer->id)->sole();
        $grant->forceFill(['status' => 'revoked'])->save();
        $this->processChanges($org);
        $this->assertDatabaseHas('calendar_change_recipients', ['recipient_id' => $reader->id, 'blocked_reason' => 'issuer_unavailable', 'delivered_at' => null]);
        $grant->forceFill(['status' => 'active'])->save();
        $this->processChanges($org);
        $this->assertSame(1, CalendarChangeRecipient::query()->whereNotNull('delivered_at')->count());
    }

    public function test_removed_private_participant_is_not_given_new_schedule_details_and_delivered_inbox_is_filtered_live(): void
    {
        [$org, $writer, $reader, , $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertOk();
        $this->processChanges($org);
        $this->actingAsWithMfa($reader);
        $this->getJson($this->changeRoot($org).'/changes')->assertJsonPath('meta.total', 1);
        $this->actingAsWithMfa($writer);
        $activity->refresh();
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'participant_ids' => []])->assertOk();
        $this->actingAsWithMfa($reader);
        $this->getJson($this->changeRoot($org).'/changes')->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('calendar_change_recipients', 1);
    }

    public function test_hidden_scope_is_rechecked_before_delivery_and_notifications_do_not_cross_tenants(): void
    {
        [$org, , , , $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertOk();
        $activity->refresh();
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'participant_ids' => []])->assertOk();
        $foreign = CalendarChangeRecipient::factory()->create();
        $this->processChanges($org);
        $this->assertDatabaseHas('calendar_change_recipients', ['organization_id' => $org->id, 'blocked_reason' => 'hidden_scope', 'delivered_at' => null]);
        $this->assertNull($foreign->refresh()->getAttribute('checked_at'));
    }

    public function test_batch_is_bounded_and_unmapped_people_remain_traceable_without_reassignment(): void
    {
        [$org, , , , $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertOk();
        $change = CalendarChange::query()->sole();
        CalendarChangeRecipient::factory()->count(25)->create(['organization_id' => $org->id, 'change_id' => $change->id]);
        $this->processChanges($org);
        $this->assertSame(25, CalendarChangeRecipient::query()->whereNotNull('checked_at')->count());
        $this->processChanges($org);
        $this->assertSame(26, CalendarChangeRecipient::query()->whereNotNull('checked_at')->count());
        $this->assertSame(25, CalendarChangeRecipient::query()->whereNull('recipient_id')->where('blocked_reason', 'recipient_unavailable')->count());
    }

    public function test_recipient_directory_failure_rolls_back_delivery_attempt_for_safe_retry(): void
    {
        [$org, , , , $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertOk();
        $this->mock(TenantNotificationRecipientDirectory::class)->shouldReceive('email')->once()->andThrow(new \RuntimeException('Directory unavailable'));
        try {
            $this->processChanges($org);
            $this->fail('Transient failures must reach the queue retry mechanism.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Directory unavailable', $exception->getMessage());
        }
        $this->assertDatabaseHas('calendar_change_recipients', ['checked_at' => null, 'delivered_at' => null, 'blocked_reason' => null]);
    }
}
