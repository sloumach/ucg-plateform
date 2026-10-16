<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaignEvent;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SponsorshipCampaignSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_schema_and_export_catalog_include_all_campaign_tables(): void
    {
        foreach (['sponsorship_campaigns', 'sponsorship_deliverables', 'sponsorship_campaign_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_sql_preserves_parent_identity_and_forbids_physical_deletion(): void
    {
        $record = SponsorshipDeliverable::factory()->create();
        $other = SponsorshipCampaign::factory()->create();
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['campaign_id' => $other->id]));
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['organization_id' => $other->organization_id]));
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->delete());
        $this->reject(fn () => DB::table('sponsorship_campaigns')->where('id', $record->campaign_id)->delete());
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $record->id, 'campaign_id' => $record->campaign_id]);
    }

    public function test_sql_refuses_wrong_agreement_exposure_even_within_the_same_tenant(): void
    {
        $record = SponsorshipDeliverable::factory()->create();
        $other = SponsorshipAgreement::factory()->create(['organization_id' => $record->organization_id, 'sponsor_id' => SponsorshipAgreement::query()->whereKey($record->agreement_id)->firstOrFail()->sponsor_id]);
        $exposure = SponsorshipExposure::query()->forceCreate(['organization_id' => $record->organization_id, 'agreement_id' => $other->id,
            'target_type' => 'organization', 'target_label' => 'Snapshot', 'channel' => 'social', 'authorization_reference' => 'Reference', 'status' => 'active', 'version' => (string) Str::uuid()]);
        $copy = $record->getAttributes();
        $copy['id'] = (string) Str::uuid();
        $copy['exposure_id'] = $exposure->id;
        $this->reject(fn () => DB::table('sponsorship_deliverables')->insert($copy));
        $this->assertDatabaseCount('sponsorship_deliverables', 1);
    }

    public function test_sql_requires_evidence_and_independent_validation_and_prevents_silent_rewrites(): void
    {
        $record = SponsorshipDeliverable::factory()->create();
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['status' => 'delivered']));
        DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['status' => 'delivered', 'proof_reference' => 'encrypted-reference', 'delivered_at' => now(), 'delivered_by' => $record->prepared_by]);
        $approval = ['status' => 'validated', 'validated_at' => now(), 'validated_by' => $record->prepared_by,
            'validated_agreement_version' => (string) Str::uuid(), 'validated_exposure_version' => (string) Str::uuid()];
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update($approval));
        $approver = User::factory()->create();
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update([...$approval, 'validated_by' => $approver->id, 'proof_reference' => 'Rewritten while validating']));
        DB::table('sponsorship_deliverables')->where('id', $record->id)->update([...$approval, 'validated_by' => $approver->id]);
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['title' => 'Silent edit']));
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['status' => 'delivered']));
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $record->id, 'status' => 'validated']);
    }

    public function test_campaign_archive_requires_settled_deliverables_and_then_freezes(): void
    {
        $record = SponsorshipDeliverable::factory()->create();
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $record->agreement_id)->update(['status' => 'archived']));
        $sponsor = SponsorshipAgreement::query()->whereKey($record->agreement_id)->firstOrFail()->sponsor_id;
        $this->reject(fn () => DB::table('sponsors')->where('id', $sponsor)->update(['status' => 'archived']));
        $this->reject(fn () => DB::table('sponsorship_campaigns')->where('id', $record->campaign_id)->update(['status' => 'archived']));
        DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['status' => 'cancelled']);
        DB::table('sponsorship_campaigns')->where('id', $record->campaign_id)->update(['status' => 'archived']);
        $this->reject(fn () => DB::table('sponsorship_campaigns')->where('id', $record->campaign_id)->update(['title' => 'Silent edit']));
        $this->reject(fn () => DB::table('sponsorship_deliverables')->where('id', $record->id)->update(['status' => 'promised']));
        $this->assertDatabaseHas('sponsorship_campaigns', ['id' => $record->campaign_id, 'status' => 'archived']);
    }

    public function test_event_snapshots_are_encrypted_append_only_and_exported_only_for_their_tenant(): void
    {
        $record = SponsorshipDeliverable::factory()->create();
        $event = SponsorshipCampaignEvent::query()->forceCreate(['organization_id' => $record->organization_id, 'campaign_id' => $record->campaign_id,
            'deliverable_id' => $record->id, 'action' => 'delivered', 'from_status' => 'promised', 'to_status' => 'delivered',
            'record_version' => $record->version, 'actor_user_id' => $record->prepared_by, 'occurred_at' => now(), 'delivery_snapshot' => ['proof_reference' => 'HISTORY_SECRET']]);
        SponsorshipDeliverable::factory()->create();
        $this->reject(fn () => DB::table('sponsorship_campaign_events')->where('id', $event->id)->update(['action' => 'validated']));
        $this->reject(fn () => DB::table('sponsorship_campaign_events')->where('id', $event->id)->delete());
        $this->assertStringNotContainsString('HISTORY_SECRET', $event->getRawOriginal('delivery_snapshot'));
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($record->organization_id, 1000));
        $export = array_values(array_filter($rows, fn (array $r): bool => $r['table'] === 'sponsorship_deliverables'));
        $this->assertCount(1, $export);
        $this->assertSame($record->id, $export[0]['data']['id']);
    }

    public function test_unused_migration_roundtrip_restores_catalog_and_used_rollback_is_refused(): void
    {
        $this->assertDatabaseCount('sponsorship_campaigns', 0);
        $migration = require database_path('migrations/2026_10_16_013526_create_sponsorship_campaigns_and_deliverables.php');
        $migration->down();
        $migration->up();
        app(PortabilityRepository::class)->assertCatalog();
        SponsorshipCampaign::factory()->create();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL must reject invalid campaign state.');
        } catch (QueryException $exception) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? '23514' : '23000', (string) $exception->getCode());
        }
    }
}
