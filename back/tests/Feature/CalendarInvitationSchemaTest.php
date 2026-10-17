<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Scheduling\Domain\Models\CalendarInvitationDelivery;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarInvitationSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_composite_foreign_keys_and_unique_occurrences_reject_foreign_data_and_duplicates(): void
    {
        $invitation = CalendarInvitation::factory()->create();
        $foreign = CalendarActivity::factory()->create();
        $person = Person::factory()->create();
        $delivery = CalendarInvitationDelivery::factory()->create(['organization_id' => $invitation->organization_id, 'invitation_id' => $invitation->id]);
        $attempts = [
            fn () => CalendarInvitation::factory()->create(['organization_id' => $invitation->organization_id, 'activity_id' => $foreign->id]),
            fn () => CalendarInvitation::factory()->create(['organization_id' => $invitation->organization_id, 'activity_id' => $invitation->activity_id, 'person_id' => $person->id]),
            fn () => CalendarInvitation::factory()->create(['organization_id' => $invitation->organization_id, 'activity_id' => $invitation->activity_id, 'person_id' => $invitation->person_id]),
            fn () => CalendarInvitationDelivery::factory()->create(['organization_id' => $foreign->organization_id, 'invitation_id' => $invitation->id]),
            fn () => CalendarInvitationDelivery::factory()->create(['organization_id' => $invitation->organization_id, 'invitation_id' => $invitation->id]),
        ];
        foreach ($attempts as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Foreign relations and duplicate delivery occurrences must fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('calendar_invitations', 1);
                $this->assertDatabaseCount('calendar_invitation_deliveries', 1);
            }
        }
        $this->assertModelExists($delivery);
    }

    public function test_identity_and_delivery_history_are_immutable_but_response_remains_editable(): void
    {
        $invitation = CalendarInvitation::factory()->create();
        $delivery = CalendarInvitationDelivery::factory()->create(['organization_id' => $invitation->organization_id, 'invitation_id' => $invitation->id]);
        foreach ([
            fn () => DB::table('calendar_invitations')->where('id', $invitation->id)->update(['activity_version' => (string) Str::uuid()]),
            fn () => DB::table('calendar_invitations')->where('id', $invitation->id)->update(['reminder_offsets' => '[30]']),
            fn () => DB::table('calendar_invitations')->where('id', $invitation->id)->delete(),
            fn () => DB::table('calendar_invitation_deliveries')->where('id', $delivery->id)->update(['kind' => 'reminder']),
            fn () => DB::table('calendar_invitation_deliveries')->where('id', $delivery->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Invitation identities and delivery history must remain immutable.');
            } catch (QueryException) {
                $this->assertModelExists($invitation);
                $this->assertModelExists($delivery);
            }
        }
        $invitation->forceFill(['response' => 'present', 'responded_at' => now()])->save();
        $this->assertDatabaseHas('calendar_invitations', ['id' => $invitation->id, 'response' => 'present']);
    }

    public function test_schema_inventory_export_and_rollback_protect_retained_invitation_data(): void
    {
        $migration = require database_path('migrations/2026_10_17_153712_create_calendar_invitations_and_deliveries.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('calendar_invitations'));
        $migration->up();
        $record = CalendarInvitationDelivery::factory()->create();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $repo = app(PortabilityRepository::class);
        $repo->assertCatalog();
        $rows = iterator_to_array($repo->rows($record->organization_id, 100));
        $deliveries = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'calendar_invitation_deliveries'));
        $this->assertCount(1, $deliveries);
        $this->assertSame($record->id, $deliveries[0]['data']['id']);
        try {
            $migration->down();
            $this->fail('Rollback must preserve populated invitation tables.');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasTable('calendar_invitations'));
            $this->assertModelExists($record);
        }
    }
}
