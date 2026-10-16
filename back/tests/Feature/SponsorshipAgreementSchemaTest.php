<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SponsorshipAgreementSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_tenant_constraints_and_export_catalog_include_all_four_tables(): void
    {
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        foreach (['sponsorship_agreements', 'sponsorship_agreement_terms', 'sponsorship_exposures', 'sponsorship_agreement_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_bulk_sql_cannot_move_agreement_identities_or_delete_history(): void
    {
        $agreement = SponsorshipAgreement::factory()->create();
        $other = Sponsor::factory()->create(['organization_id' => $agreement->organization_id]);
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update(['sponsor_id' => $other->id]));
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update(['reference' => 'changed']));
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->delete());
        $this->assertDatabaseHas('sponsorship_agreements', ['id' => $agreement->id, 'sponsor_id' => $agreement->sponsor_id]);
    }

    public function test_sql_approval_requires_terms_an_independent_approver_and_an_active_exposure(): void
    {
        $agreement = SponsorshipAgreement::factory()->create();
        $approver = User::factory()->create();
        $version = (string) Str::uuid();
        $approval = ['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'approved_financial_version' => $version];
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update($approval));
        $this->terms($agreement, $version);
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update($approval));
        $this->exposure($agreement);
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update([...$approval, 'approved_by' => $agreement->prepared_by]));
        DB::table('sponsorship_agreements')->where('id', $agreement->id)->update($approval);
        $this->assertDatabaseHas('sponsorship_agreements', ['id' => $agreement->id, 'status' => 'approved']);
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update(['title' => 'Silent revision']));
        $this->reject(fn () => DB::table('sponsorship_agreement_terms')->where('agreement_id', $agreement->id)->update(['version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('sponsorship_exposures')->where('agreement_id', $agreement->id)->update(['channel' => 'social']));
    }

    public function test_sql_exposure_shapes_and_composite_tenant_foreign_keys_are_enforced(): void
    {
        $agreement = SponsorshipAgreement::factory()->create();
        $foreignTeam = Team::factory()->create();
        $row = $this->exposureRow($agreement);
        $this->reject(fn () => DB::table('sponsorship_exposures')->insert([...$row, 'target_type' => 'team']));
        $this->reject(fn () => DB::table('sponsorship_exposures')->insert([...$row, 'target_type' => 'team', 'team_id' => $foreignTeam->id]), '23503');
        $exposure = $this->exposure($agreement);
        $other = SponsorshipAgreement::factory()->create(['sponsor_id' => $agreement->sponsor_id, 'organization_id' => $agreement->organization_id]);
        $this->reject(fn () => DB::table('sponsorship_exposures')->where('id', $exposure)->update(['agreement_id' => $other->id]));
    }

    public function test_history_is_append_only_and_archives_and_periods_cannot_be_bypassed(): void
    {
        $agreement = SponsorshipAgreement::factory()->create();
        $id = (string) Str::uuid();
        DB::table('sponsorship_agreement_events')->insert(['id' => $id, 'organization_id' => $agreement->organization_id, 'agreement_id' => $agreement->id,
            'action' => 'created', 'from_status' => null, 'to_status' => 'draft', 'agreement_version' => $agreement->version,
            'actor_user_id' => $agreement->prepared_by, 'occurred_at' => now()]);
        $this->reject(fn () => DB::table('sponsorship_agreement_events')->where('id', $id)->update(['action' => 'approved']));
        $this->reject(fn () => DB::table('sponsorship_agreement_events')->where('id', $id)->delete());
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update(['ends_on' => '1900-01-01']));
        DB::table('sponsorship_agreements')->where('id', $agreement->id)->update(['status' => 'archived']);
        $this->reject(fn () => DB::table('sponsorship_agreements')->where('id', $agreement->id)->update(['title' => 'Overwrite']));
        $this->reject(fn () => $this->exposure($agreement));
    }

    public function test_rollback_refuses_to_drop_used_agreements(): void
    {
        SponsorshipAgreement::factory()->create();
        $migration = require database_path('migrations/2026_10_16_000918_create_sponsorship_agreements_and_exposures.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function terms(SponsorshipAgreement $a, string $version): void
    {
        DB::table('sponsorship_agreement_terms')->insert(['id' => (string) Str::uuid(), 'organization_id' => $a->organization_id,
            'agreement_id' => $a->id, 'terms' => 'encrypted-payload-placeholder', 'version' => $version, 'prepared_by' => $a->prepared_by]);
    }

    /** @return array<string, mixed> */
    private function exposureRow(SponsorshipAgreement $a): array
    {
        return ['id' => (string) Str::uuid(), 'organization_id' => $a->organization_id, 'agreement_id' => $a->id,
            'target_type' => 'organization', 'team_id' => null, 'person_id' => null, 'target_label' => 'encrypted-label-placeholder',
            'channel' => 'website', 'authorization_reference' => 'encrypted-proof-placeholder', 'status' => 'active', 'version' => (string) Str::uuid()];
    }

    private function exposure(SponsorshipAgreement $a): string
    {
        $row = $this->exposureRow($a);
        DB::table('sponsorship_exposures')->insert($row);

        return $row['id'];
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write, string $postgresCode = '23514'): void
    {
        try {
            DB::transaction($write);
            $this->fail('The SQL guard must refuse this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? $postgresCode : '23000', (string) $error->getCode());
        }
    }
}
