<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Contracts\CompetitionRegistrationRepository;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class RoleDashboardTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;
    use ProvidesContractDocuments;

    /** @param list<string> $permissions
     * @return array<string,mixed> */
    private function scenario(array $permissions): array
    {
        $this->travelTo('2026-10-19 10:00:00 UTC');
        $org = Organization::factory()->create(['timezone' => 'Europe/Paris']);
        $user = User::factory()->withMfa()->create();
        $assignment = $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return compact('org', 'user', 'assignment') + ['root' => '/api/v1/tenants/'.$org->id.'/dashboards'];
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $input */
    private function card(array $s, string $card, string $view, array $input = []): string
    {
        return $s['root'].'/'.$card.'?'.http_build_query(['view' => $view, 'from' => '2026-10-19', 'to' => '2026-10-25', ...$input]);
    }

    #[TestWith(['direction'])]
    #[TestWith(['manager'])]
    #[TestWith(['coach'])]
    #[TestWith(['player'])]
    #[TestWith(['administration'])]
    public function test_each_layout_requires_its_explicit_permission_without_granting_domain_access(string $view): void
    {
        $s = $this->scenario(['reporting.'.$view]);
        $this->getJson($s['root'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data.views')->assertJsonPath('data.views.0.id', $view)->assertJsonCount(0, 'data.views.0.cards');
        $context = $this->personContext($s['org'], $s['user']);
        $this->assertTrue(Gate::forUser($s['user'])->allows('reporting.viewAny', $context));
        $other = User::factory()->withMfa()->create();
        $this->assertFalse(Gate::forUser($other)->allows('reporting.viewAny', $context));
        $wrong = $view === 'direction' ? 'coach' : 'direction';
        $this->getJson($this->card($s, 'matches', $wrong))->assertForbidden();
    }

    public function test_direction_effective_counts_and_pages_are_tenant_scoped_and_do_not_disclose_administrative_fields(): void
    {
        $s = $this->scenario(['reporting.direction', 'sports.read']);
        Person::factory()->count(6)->create(['organization_id' => $s['org']->id, 'status' => 'active', 'pseudonym' => '<script>Joueur</script>', 'contact_email' => 'private@example.test']);
        Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'former']);
        Person::factory()->create(['status' => 'active']);
        $response = $this->getJson($this->card($s, 'active_people', 'direction'))->assertOk()->assertJsonPath('data.total', 6)
            ->assertJsonCount(5, 'data.items')->assertJsonPath('data.period.kind', 'snapshot')->assertJsonPath('data.period.from', null);
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('private@example.test', $body);
        $this->getJson($this->card($s, 'active_people', 'direction', ['page' => 2]))->assertOk()->assertJsonCount(1, 'data.items');
        $catalogue = $this->getJson($s['root'])->assertOk()->json('data.views.0.cards');
        $this->assertSame(['active_people', 'matches'], array_column($catalogue, 'id'));
        $this->getJson($this->card($s, 'expiring_contracts', 'direction'))->assertForbidden();
    }

    public function test_manager_window_includes_the_last_local_day_and_excludes_other_match_audiences(): void
    {
        $s = $this->scenario(['reporting.manager', 'sports.read', 'calendar.read']);
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'visibility' => 'organization',
            'sharing_authorized_by' => $s['user']->id, 'sharing_authorized_at' => now(), 'starts_at' => '2026-10-25 22:00:00', 'ends_at' => '2026-10-25 22:30:00']);
        MatchRecord::factory()->create(['organization_id' => $s['org']->id]);
        MatchRecord::factory()->create();
        $this->getJson($this->card($s, 'matches', 'manager'))->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $match->id)->assertJsonPath('data.period.timezone', 'Europe/Paris');
        $match->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'version' => (string) Str::uuid()])->save();
        $this->getJson($this->card($s, 'matches', 'manager'))->assertOk()->assertJsonPath('data.total', 0);
        CalendarActivity::factory()->shared()->create(['organization_id' => $s['org']->id, 'starts_at' => '2026-10-25 22:00:00', 'ends_at' => '2026-10-25 22:30:00']);
        $this->getJson($this->card($s, 'activities', 'manager'))->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_player_only_reads_own_availability_and_current_sent_invitations(): void
    {
        $s = $this->scenario(['reporting.player', 'availability.self', 'calendar.read']);
        $person = Person::factory()->create(['organization_id' => $s['org']->id, 'user_id' => $s['user']->id]);
        $other = Person::factory()->create(['organization_id' => $s['org']->id]);
        Availability::factory()->create(['organization_id' => $s['org']->id, 'person_id' => $person->id, 'starts_at' => '2026-10-20 08:00:00', 'ends_at' => '2026-10-20 09:00:00', 'comment' => 'Private comment']);
        Availability::factory()->create(['organization_id' => $s['org']->id, 'person_id' => $other->id, 'starts_at' => '2026-10-20 08:00:00', 'ends_at' => '2026-10-20 09:00:00']);
        $this->getJson($this->card($s, 'availability', 'player'))->assertOk()->assertJsonPath('data.total', 1)->assertJsonMissingPath('data.items.0.comment');
        $activity = CalendarActivity::factory()->create(['organization_id' => $s['org']->id]);
        CalendarParticipant::factory()->create(['organization_id' => $s['org']->id, 'activity_id' => $activity->id, 'person_id' => $person->id]);
        CalendarInvitation::factory()->create(['activity_id' => $activity->id, 'person_id' => $person->id, 'recipient_id' => $s['user']->id, 'sent_at' => now()]);
        CalendarInvitation::factory()->create(['activity_id' => $activity->id, 'person_id' => $other->id, 'recipient_id' => $s['user']->id, 'sent_at' => now()]);
        $this->getJson($this->card($s, 'invitations', 'player'))->assertOk()->assertJsonPath('data.total', 1)->assertJsonMissingPath('data.items.0.recipient_id');
    }

    public function test_no_linked_person_does_not_turn_a_personal_card_into_a_collective_query(): void
    {
        $s = $this->scenario(['reporting.player', 'availability.self']);
        Availability::factory()->create(['organization_id' => $s['org']->id, 'starts_at' => '2026-10-20 08:00:00', 'ends_at' => '2026-10-20 09:00:00']);
        $this->getJson($this->card($s, 'availability', 'player'))->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_administration_quality_metadata_never_projects_civil_identity_or_contacts(): void
    {
        $s = $this->scenario(['reporting.administration', 'administrative.read']);
        Person::factory()->create(['organization_id' => $s['org']->id, 'first_name' => 'Secret civil name', 'user_id' => null, 'contact_email' => 'secret@example.test']);
        Person::factory()->create(['organization_id' => $s['org']->id, 'user_id' => $s['user']->id]);
        $response = $this->getJson($this->card($s, 'unlinked_people', 'administration'))->assertOk()->assertJsonPath('data.total', 1);
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Secret civil name', $body);
        $this->assertStringNotContainsString('secret@example.test', $body);
    }

    public function test_contract_expiry_only_includes_signed_or_active_contracts_in_the_window_without_financial_fields(): void
    {
        $this->travelTo('2026-10-19 10:00:00 UTC');
        [$org, $user, $contract, $context] = $this->documentScenario(['reporting.direction', 'administrative.read', 'administrative.write', 'administrative.validate']);
        $contract->forceFill(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-25'])->save();
        $this->declareContractSignature($org, $contract, $context);
        $this->actingAsWithMfa($user);
        $s = ['org' => $org, 'user' => $user, 'root' => '/api/v1/tenants/'.$org->id.'/dashboards'];
        ContractRecord::factory()->create(['organization_id' => $org->id, 'responsible_person_id' => $contract->responsible_person_id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-25']);
        $this->getJson($this->card($s, 'expiring_contracts', 'direction'))->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.label', $contract->reference)->assertJsonMissingPath('data.items.0.title')->assertJsonMissingPath('data.items.0.financial_terms');
    }

    public function test_registration_card_uses_only_the_latest_persisted_deadline_and_hides_payloads(): void
    {
        $s = $this->scenario(['reporting.manager', 'sports.read', 'sports.competitions.read', 'sports.registrations.read']);
        $competition = ExternalCompetition::factory()->create(['organization_id' => $s['org']->id]);
        $records = app(CompetitionRegistrationRepository::class);
        $attributes = ['id' => (string) Str::uuid(), 'organization_id' => $s['org']->id, 'competition_id' => $competition->id,
            'number' => 1, 'payload' => ['notes' => 'Private registration note'], 'reason' => 'Motif privé', 'actor_user_id' => $s['user']->id,
            'next_due_at' => '2026-10-20 10:00:00', 'checked_at' => null];
        $records->append($attributes);
        $this->getJson($this->card($s, 'registration_due', 'manager'))->assertOk()->assertJsonPath('data.total', 1)->assertJsonMissingPath('data.items.0.payload');
        $records->append([...$attributes, 'id' => (string) Str::uuid(), 'number' => 2, 'next_due_at' => null]);
        $this->getJson($this->card($s, 'registration_due', 'manager'))->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_unimplemented_indicators_are_unavailable_rather_than_zero_and_never_dispatch_a_job(): void
    {
        $s = $this->scenario(['reporting.direction', 'financial.read', 'reporting.coach', 'sports.read', 'sports.statistics.read']);
        Queue::fake();
        $this->getJson($this->card($s, 'budgets', 'direction'))->assertOk()->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.total', null);
        $this->getJson($this->card($s, 'team_progress', 'coach'))->assertOk()->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.total', null);
        $this->getJson($this->card($s, 'calculations', 'coach'))->assertOk()->assertJsonPath('data.total', 0);
        Queue::assertNothingPushed();
    }

    public function test_each_source_card_has_a_closed_paginated_response_and_no_mutation_route(): void
    {
        $s = $this->scenario(['reporting.administration', 'reporting.direction', 'sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'partnerships.read']);
        $this->getJson($this->card($s, 'people_imports', 'administration'))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($this->card($s, 'overdue_deliverables', 'direction'))->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson($s['root'], [])->assertMethodNotAllowed();
    }

    public function test_imports_and_overdue_promises_are_projected_without_source_payloads_or_other_tenants(): void
    {
        $s = $this->scenario(['reporting.administration', 'reporting.direction', 'sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'partnerships.read']);
        $import = PersonImport::factory()->create(['organization_id' => $s['org']->id, 'headers' => ['Private import header']]);
        PersonImport::factory()->create();
        $this->getJson($this->card($s, 'people_imports', 'administration'))->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $import->id)->assertJsonMissingPath('data.items.0.headers');
        $sponsor = Sponsor::factory()->create(['organization_id' => $s['org']->id]);
        $agreement = SponsorshipAgreement::factory()->create(['sponsor_id' => $sponsor->id]);
        $campaign = SponsorshipCampaign::factory()->create(['agreement_id' => $agreement->id]);
        $due = SponsorshipDeliverable::factory()->create(['campaign_id' => $campaign->id, 'due_at' => now()->subHour()]);
        SponsorshipDeliverable::factory()->create(['campaign_id' => $campaign->id, 'due_at' => now()->addDay()]);
        SponsorshipDeliverable::factory()->create(['due_at' => now()->subHour()]);
        $this->getJson($this->card($s, 'overdue_deliverables', 'direction'))->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $due->id)->assertJsonMissingPath('data.items.0.proof_reference');
    }

    public function test_coach_match_audience_follows_actual_team_membership_without_management_expansion(): void
    {
        $s = $this->scenario(['reporting.coach', 'sports.read', 'sports.write']);
        $person = Person::factory()->create(['organization_id' => $s['org']->id, 'user_id' => $s['user']->id]);
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'visibility' => 'team', 'sharing_authorized_by' => $s['user']->id, 'sharing_authorized_at' => now()]);
        $team = Team::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $match->game_id]);
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id]);
        (new MatchParticipant)->forceFill(['id' => (string) Str::uuid(), 'organization_id' => $s['org']->id, 'match_id' => $match->id,
            'kind' => 'team', 'team_id' => $team->id, 'slot' => 1, 'label' => 'Équipe', 'reference_version' => $team->version])->save();
        $this->getJson($this->card($s, 'matches', 'coach'))->assertOk()->assertJsonPath('data.total', 1);
        $period->forceFill(['ends_at' => now(), 'status' => 'closed'])->save();
        $this->getJson($this->card($s, 'matches', 'coach'))->assertOk()->assertJsonPath('data.total', 0);
    }

    #[Group('tenant-isolation')]
    public function test_revocation_tenant_boundaries_authentication_and_mfa_are_rechecked(): void
    {
        $s = $this->scenario(['reporting.direction', 'sports.read']);
        $this->getJson($s['root'])->assertOk();
        $other = Organization::factory()->create();
        $this->getJson('/api/v1/tenants/'.$other->id.'/dashboards')->assertNotFound();
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        $this->getJson($s['root'])->assertForbidden();
        $s['assignment']->forceFill(['status' => 'active'])->save();
        $this->actingAsWithMfa($s['user']);
        $this->getJson($s['root'])->assertOk();
        $this->withSession([MfaSession::PROOF_KEY => null])->getJson($s['root'])->assertForbidden();
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->getJson($s['root'])->assertUnauthorized();
    }

    public function test_closed_filters_and_calendar_window_limits_are_validated_before_reading_a_source(): void
    {
        $s = $this->scenario(['reporting.direction', 'sports.read']);
        foreach ([['from' => '2026-09-01'], ['to' => '2026-10-18'], ['page' => 0], ['scope' => 'all'], ['organization_id' => (string) Str::uuid()], ['view' => 'root']] as $invalid) {
            $this->getJson($this->card($s, 'active_people', 'direction', $invalid))->assertUnprocessable();
        }
        $this->getJson($s['root'].'?view=direction')->assertUnprocessable();
        $this->getJson($this->card($s, 'unknown', 'direction'))->assertNotFound();
    }

    #[TestWith(['array'])]
    #[TestWith(['database'])]
    public function test_query_count_remains_constant_with_more_matches_and_source_corruption_is_not_reported_as_zero(string $cacheStore): void
    {
        config(['tenancy.cache_store' => $cacheStore]);
        $s = $this->scenario(['reporting.manager', 'sports.read']);
        $attributes = ['organization_id' => $s['org']->id, 'visibility' => 'organization', 'sharing_authorized_by' => $s['user']->id, 'sharing_authorized_at' => now()];
        $match = MatchRecord::factory()->create($attributes);

        // Initialize the rate limiter's cache entries before comparing equally warm requests.
        $this->getJson($this->card($s, 'matches', 'manager'))->assertOk()->assertJsonPath('data.total', 1);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->getJson($this->card($s, 'matches', 'manager'))->assertOk()->assertJsonPath('data.total', 1)->assertJsonCount(1, 'data.items');
            $queries = count(DB::getQueryLog());
            MatchRecord::factory()->count(4)->create($attributes);
            DB::flushQueryLog();
            $this->getJson($this->card($s, 'matches', 'manager'))->assertOk()->assertJsonPath('data.total', 5)->assertJsonCount(5, 'data.items');
            $this->assertSame($queries, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        DB::table('matches')->where('id', $match->id)->update(['title' => 'corrupt ciphertext', 'version' => (string) Str::uuid()]);
        $this->getJson($this->card($s, 'matches', 'manager'))->assertConflict()->assertJsonPath('code', 'REPORTING_SOURCE_UNAVAILABLE');
    }
}
