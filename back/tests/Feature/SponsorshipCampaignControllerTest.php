<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class SponsorshipCampaignControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess;

    private const array EDITOR = ['partnerships.read', 'partnerships.write', 'partnerships.validate', 'financial.read', 'financial.write', 'financial.validate'];

    /**
     * @return array<string,array{array<string,mixed>,string}> */
    public static function invalidDeliveries(): array
    {
        return ['empty proof' => [['proof_reference' => '   '], 'proof_reference'], 'oversized proof' => [['proof_reference' => str_repeat('x', 2001)], 'proof_reference'],
            'future date' => [['delivered_at' => '2027-01-01T00:00:00Z'], 'delivered_at'], 'missing timezone' => [['delivered_at' => '2026-10-16T10:00:00'], 'delivered_at'],
            'unknown measurement' => [['audience' => 999999], 'audience'], 'invalid version' => [['version' => 'stale'], 'version']];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('invalidDeliveries')]
    public function test_invalid_delivery_declarations_are_rejected_without_changing_the_promise(array $overrides, string $field): void
    {
        [, $author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $body = [...$this->versions($agreement, $campaign, $promise), 'delivered_at' => now()->toIso8601String(), 'proof_reference' => 'Reference'];
        $this->postJson($this->root($sponsor, $agreement).'/'.$campaign['id'].'/deliverables/'.$promise['id'].'/deliveries', [...$body, ...$overrides])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $promise['id'], 'status' => 'promised', 'proof_reference' => null]);
        $this->assertDatabaseCount('sponsorship_campaign_events', 2);
    }

    public function test_agreement_period_cannot_exclude_an_active_campaign_in_http_or_sql(): void
    {
        [, $author,$sponsor,$agreement,$scope] = $this->scenario();
        $this->prepared($sponsor, $agreement, $scope, $author);
        $this->patchJson($this->agreementRoot($sponsor).'/'.$agreement['id'], ['version' => $agreement['version'], 'ends_on' => today()->format('Y-m-d')])
            ->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_PERIOD');
        try {
            DB::transaction(fn () => DB::table('sponsorship_agreements')->where('id', $agreement['id'])->update(['ends_on' => today()->format('Y-m-d')]));
            $this->fail('SQL must retain the active campaign period.');
        } catch (QueryException) {
            $this->assertDatabaseHas('sponsorship_agreements', ['id' => $agreement['id'], 'version' => $agreement['version']]);
        }
    }

    public function test_validation_confirmation_and_responsible_revocation_are_checked_again(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario(true);
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $path = $this->root($sponsor, $agreement).'/'.$campaign['id'].'/deliverables/'.$promise['id'];
        $delivery = $this->postJson($path.'/deliveries', [...$this->versions($agreement, $campaign, $promise), 'delivered_at' => now()->toIso8601String(), 'proof_reference' => 'Preuve'])->assertOk()->json('data');
        $campaign['version'] = $delivery['campaign_version'];
        $this->actingAsWithMfa($this->account($org, ['partnerships.read', 'partnerships.write', 'partnerships.validate']));
        $body = [...$this->versions($agreement, $campaign, $delivery), 'target_status' => 'validated'];
        $this->postJson($path.'/transitions', [...$body, 'confirm_evidence' => false])->assertUnprocessable()->assertJsonValidationErrors('confirm_evidence');
        OrganizationRoleAssignment::query()->where('user_id', $author->id)->update(['status' => 'suspended', 'version' => (string) Str::uuid()]);
        $this->postJson($path.'/transitions', [...$body, 'confirm_evidence' => true])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_AUTHORIZATION');
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $promise['id'], 'status' => 'delivered', 'validated_by' => null]);
    }

    public function test_campaign_delivery_and_independent_validation_preserve_private_evidence(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario(true);
        $root = $this->root($sponsor, $agreement);
        $campaign = $this->postJson($root, $this->campaignPayload($agreement, $author))->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
        $path = $root.'/'.$campaign['id'];
        $promise = $this->postJson($path.'/deliverables', $this->promise($agreement, $campaign, $scope, $author))->assertCreated()
            ->assertJsonPath('data.status', 'promised')->assertJsonPath('data.proof_reference', null)->json('data');
        $campaign['version'] = $promise['campaign_version'];
        $this->patchJson($path, ['agreement_version' => $agreement['version'], 'version' => $campaign['version'], 'status' => 'archived'])->assertConflict();
        $delivered = $this->postJson($path.'/deliverables/'.$promise['id'].'/deliveries', [...$this->versions($agreement, $campaign, $promise),
            'delivered_at' => now()->subHour()->toIso8601String(), 'proof_reference' => 'PROOF_SECRET <script>unsafe</script>'])->assertOk()
            ->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.validated_by', null)->json('data');
        $campaign['version'] = $delivered['campaign_version'];
        $body = [...$this->versions($agreement, $campaign, $delivered), 'target_status' => 'validated', 'confirm_evidence' => true];
        $this->postJson($path.'/deliverables/'.$promise['id'].'/transitions', $body)->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_INDEPENDENT');
        $reviewer = $this->account($org, ['partnerships.read', 'partnerships.write', 'partnerships.validate']);
        $this->actingAsWithMfa($reviewer);
        $validated = $this->postJson($path.'/deliverables/'.$promise['id'].'/transitions', $body)->assertOk()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.validated_by', $reviewer->id)->assertJsonPath('data.validation_matches_agreement', true)->json('data');
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $promise['id'], 'status' => 'validated', 'validated_by' => $reviewer->id]);
        $this->assertDatabaseCount('sponsorship_campaign_events', 4);
        $stored = DB::table('sponsorship_deliverables')->where('id', $promise['id'])->value('proof_reference');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('PROOF_SECRET', $stored);
        $history = $this->getJson($path.'/history?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.delivery_snapshot');
        $this->assertStringNotContainsString('PROOF_SECRET', $history->getContent() ?: '');
        $this->assertStringNotContainsString('PROOF_SECRET', json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR));
        $this->getJson($path.'/deliverables')->assertOk()->assertJsonMissingPath('data.0.terms')->assertJsonMissingPath('data.0.contract_id');
        $this->actingAsWithMfa($author);
        $this->patchJson($this->agreementRoot($sponsor).'/'.$agreement['id'], ['version' => $agreement['version'], 'title' => 'Accord révisé'])->assertOk();
        $this->getJson($path.'/deliverables')->assertOk()->assertJsonPath('data.0.status', 'validated')->assertJsonPath('data.0.validation_matches_agreement', false)->assertJsonPath('data.0.exposure_authorized', false);
        $this->assertNotSame($delivered['version'], $validated['version']);
    }

    public function test_a_declared_delivery_does_not_approve_a_draft_agreement(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $path = $this->root($sponsor, $agreement).'/'.$campaign['id'].'/deliverables/'.$promise['id'];
        $delivery = $this->postJson($path.'/deliveries', [...$this->versions($agreement, $campaign, $promise), 'delivered_at' => now()->toIso8601String(), 'proof_reference' => 'Référence manuelle'])->assertOk()
            ->assertJsonPath('data.exposure_authorized', false)->json('data');
        $campaign['version'] = $delivery['campaign_version'];
        $this->actingAsWithMfa($this->account($org, ['partnerships.read', 'partnerships.write', 'partnerships.validate']));
        $this->postJson($path.'/transitions', [...$this->versions($agreement, $campaign, $delivery), 'target_status' => 'validated', 'confirm_evidence' => true])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_AUTHORIZATION');
        $this->assertDatabaseHas('sponsorship_agreements', ['id' => $agreement['id'], 'status' => 'draft']);
    }

    public function test_reader_and_financial_permissions_do_not_allow_campaign_mutations(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $root = $this->root($sponsor, $agreement);
        $this->actingAsWithMfa($this->account($org, ['partnerships.read']));
        $this->getJson($root)->assertOk();
        $this->getJson($root.'/'.$campaign['id'].'/deliverables')->assertOk();
        $this->postJson($root, $this->campaignPayload($agreement, $author))->assertForbidden();
        $this->postJson($root.'/'.$campaign['id'].'/deliverables/'.$promise['id'].'/deliveries', [])->assertForbidden();
        $this->actingAsWithMfa($this->account($org, ['financial.read', 'financial.write', 'financial.validate']));
        $this->getJson('/api/v1/tenants/'.$org->id.'/sponsorship-dashboard')->assertForbidden();
        $this->getJson($root)->assertForbidden();
        $this->actingAsWithMfa($this->account($org, ['partnerships.read', 'partnerships.write']));
        $this->postJson($root.'/'.$campaign['id'].'/deliverables/'.$promise['id'].'/transitions',
            [...$this->versions($agreement, $campaign, $promise), 'target_status' => 'validated', 'confirm_evidence' => true])->assertForbidden();
    }

    public function test_unknown_fields_periods_responsibles_and_missing_inputs_are_refused(): void
    {
        [$org,$author,$sponsor,$agreement] = $this->scenario();
        $root = $this->root($sponsor, $agreement);
        $this->postJson($root, [])->assertUnprocessable()->assertJsonValidationErrors(['agreement_version', 'title', 'starts_on', 'ends_on', 'responsible_user_id']);
        $payload = $this->campaignPayload($agreement, $author);
        $this->postJson($root, [...$payload, 'organization_id' => $org->id])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->postJson($root, [...$payload, 'starts_on' => today()->subYear()->format('Y-m-d')])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_PERIOD');
        $outsider = User::factory()->withMfa()->create();
        $this->postJson($root, [...$payload, 'responsible_user_id' => $outsider->id])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_RESPONSIBLE');
        $this->assertDatabaseCount('sponsorship_campaigns', 0);
    }

    #[Group('tenant-isolation')]
    public function test_cross_tenant_cross_agreement_and_cross_campaign_identifiers_never_resolve(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $foreign = SponsorshipCampaign::factory()->create();
        $root = $this->root($sponsor, $agreement);
        $this->getJson($root.'/'.$foreign->id)->assertNotFound();
        $this->getJson($root.'/'.$foreign->id.'/deliverables')->assertNotFound();
        $other = SponsorshipAgreement::factory()->create(['organization_id' => $org->id, 'sponsor_id' => $sponsor->id]);
        $this->getJson($this->root($sponsor, ['id' => $other->id]).'/'.$campaign['id'])->assertNotFound();
        $otherPromise = SponsorshipDeliverable::factory()->create();
        $this->patchJson($root.'/'.$campaign['id'].'/deliverables/'.$otherPromise->id,
            [...$this->versions($agreement, $campaign, $otherPromise->toArray()), 'title' => 'Intrusion'])->assertNotFound();
        $this->postJson($root.'/'.$campaign['id'].'/deliverables', [...$this->promise($agreement, $campaign, $scope, $author), 'exposure_id' => $otherPromise->exposure_id])->assertNotFound();
        $this->assertDatabaseCount('sponsorship_campaign_events', 2);
    }

    public function test_all_three_versions_are_checked_before_mutating(): void
    {
        [, $author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $path = $this->root($sponsor, $agreement).'/'.$campaign['id'].'/deliverables/'.$promise['id'];
        foreach (['version', 'campaign_version', 'agreement_version'] as $field) {
            $this->patchJson($path, [...$this->versions($agreement, $campaign, $promise), $field => (string) Str::uuid(), 'title' => 'Stale'])->assertConflict();
        }
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $promise['id'], 'title' => 'Vidéo promise', 'status' => 'promised']);
        $this->assertDatabaseCount('sponsorship_campaign_events', 2);
    }

    public function test_due_dates_use_the_tenant_timezone_and_campaign_edits_keep_existing_due_dates(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario();
        $org->forceFill(['timezone' => 'Europe/Paris'])->save();
        $root = $this->root($sponsor, $agreement);
        $campaign = $this->postJson($root, $this->campaignPayload($agreement, $author))->assertCreated()->json('data');
        $body = $this->promise($agreement, $campaign, $scope, $author);
        $this->postJson($root.'/'.$campaign['id'].'/deliverables', [...$body, 'due_at' => today()->subDay()->format('Y-m-d').'T21:59:59Z'])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_DUE');
        $promise = $this->postJson($root.'/'.$campaign['id'].'/deliverables', [...$body, 'due_at' => today()->addDays(2)->format('Y-m-d').'T23:00:00+02:00'])->assertCreated()->json('data');
        $this->patchJson($root.'/'.$campaign['id'], ['agreement_version' => $agreement['version'], 'version' => $promise['campaign_version'], 'ends_on' => today()->addDay()->format('Y-m-d')])
            ->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_PERIOD');
    }

    public function test_reopening_preserves_the_previous_encrypted_evidence_and_clears_approval(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario(true);
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $path = $this->root($sponsor, $agreement).'/'.$campaign['id'].'/deliverables/'.$promise['id'];
        $delivery = $this->postJson($path.'/deliveries', [...$this->versions($agreement, $campaign, $promise), 'delivered_at' => now()->toIso8601String(), 'proof_reference' => 'OLD_PROOF'])->assertOk()->json('data');
        $campaign['version'] = $delivery['campaign_version'];
        $this->patchJson($path, [...$this->versions($agreement, $campaign, $delivery), 'title' => 'Silent edit'])->assertConflict();
        $this->actingAsWithMfa($this->account($org, ['partnerships.read', 'partnerships.write', 'partnerships.validate']));
        $validation = $this->postJson($path.'/transitions', [...$this->versions($agreement, $campaign, $delivery), 'target_status' => 'validated', 'confirm_evidence' => true])->assertOk()->json('data');
        $campaign['version'] = $validation['campaign_version'];
        $reopened = $this->postJson($path.'/transitions', [...$this->versions($agreement, $campaign, $validation), 'target_status' => 'promised'])->assertOk()
            ->assertJsonPath('data.proof_reference', null)->assertJsonPath('data.validated_by', null)->assertJsonPath('data.status', 'promised')->json('data');
        $this->assertNotSame($validation['version'], $reopened['version']);
        $event = DB::table('sponsorship_campaign_events')->where('action', 'reopened')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('OLD_PROOF', $event->delivery_snapshot);
        $snapshot = decrypt($event->delivery_snapshot, false);
        $this->assertStringContainsString('OLD_PROOF', $snapshot);
    }

    public function test_cancellation_then_archival_freezes_the_campaign(): void
    {
        [, $author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $root = $this->root($sponsor, $agreement);
        $path = $root.'/'.$campaign['id'];
        $cancelled = $this->postJson($path.'/deliverables/'.$promise['id'].'/transitions',
            [...$this->versions($agreement, $campaign, $promise), 'target_status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled')->json('data');
        $archived = $this->patchJson($path, ['agreement_version' => $agreement['version'], 'version' => $cancelled['campaign_version'], 'status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived')->json('data');
        $this->postJson($path.'/deliverables', $this->promise($agreement, $archived, $scope, $author))->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_FROZEN');
        $this->assertDatabaseHas('sponsorship_deliverables', ['id' => $promise['id'], 'status' => 'cancelled']);
    }

    #[Group('tenant-isolation')]
    public function test_dashboard_is_paginated_scoped_and_excludes_evidence_and_finances(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign] = $this->prepared($sponsor, $agreement, $scope, $author);
        SponsorshipDeliverable::factory()->count(3)->create(['organization_id' => $org->id, 'campaign_id' => $campaign['id'], 'agreement_id' => $agreement['id'], 'exposure_id' => $scope, 'responsible_user_id' => $author->id, 'due_at' => now()->subHours(3)]);
        SponsorshipDeliverable::factory()->create(['due_at' => now()->subDay()]);
        $response = $this->getJson('/api/v1/tenants/'.$org->id.'/sponsorship-dashboard?per_page=2&page=1')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.overdue', 4)->assertJsonPath('summary.promised', 4)->assertJsonPath('summary.delivered', 0)->assertJsonPath('meta.total', 4);
        $response->assertJsonMissingPath('data.0.proof_reference')->assertJsonMissingPath('data.0.terms')->assertJsonMissingPath('data.0.organization_id');
        $this->assertSame('private', $response->json('data.0.publication_status'));
        $this->getJson('/api/v1/tenants/'.$org->id.'/sponsorship-dashboard?per_page=101')->assertUnprocessable();
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/tenants/'.Str::uuid().'/sponsorship-dashboard')->assertUnauthorized();
    }

    public function test_active_campaigns_must_be_archived_before_the_parent_agreement_or_sponsor(): void
    {
        [, $author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign,$promise] = $this->prepared($sponsor, $agreement, $scope, $author);
        $this->postJson($this->agreementRoot($sponsor).'/'.$agreement['id'].'/transitions', ['version' => $agreement['version'], 'target_status' => 'archived'])
            ->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_ACTIVE_CAMPAIGNS');
        $this->patchJson('/api/v1/tenants/'.$sponsor->organization_id.'/sponsors/'.$sponsor->id, ['version' => $sponsor->version, 'status' => 'archived'])
            ->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_CAMPAIGN_ACTIVE_CAMPAIGNS');
        $path = $this->root($sponsor, $agreement).'/'.$campaign['id'];
        $cancelled = $this->postJson($path.'/deliverables/'.$promise['id'].'/transitions', [...$this->versions($agreement, $campaign, $promise), 'target_status' => 'cancelled'])->assertOk()->json('data');
        $this->patchJson($path, ['agreement_version' => $agreement['version'], 'version' => $cancelled['campaign_version'], 'status' => 'archived'])->assertOk();
        $this->postJson($this->agreementRoot($sponsor).'/'.$agreement['id'].'/transitions', ['version' => $agreement['version'], 'target_status' => 'archived'])->assertOk();
    }

    public function test_reference_labels_and_dashboard_reads_do_not_add_queries_per_deliverable(): void
    {
        [$org,$author,$sponsor,$agreement,$scope] = $this->scenario();
        [$campaign] = $this->prepared($sponsor, $agreement, $scope, $author);
        SponsorshipDeliverable::factory()->count(8)->create(['organization_id' => $org->id, 'agreement_id' => $agreement['id'], 'campaign_id' => $campaign['id'], 'exposure_id' => $scope, 'responsible_user_id' => $author->id, 'due_at' => now()->subHour()]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->root($sponsor, $agreement).'/'.$campaign['id'].'/deliverables?per_page=9')->assertOk()->assertJsonCount(9, 'data');
        $scopes = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "sponsorship_exposures"'));
        $this->assertCount(1, $scopes);
        DB::flushQueryLog();
        $this->getJson('/api/v1/tenants/'.$org->id.'/sponsorship-dashboard?per_page=9')->assertOk()->assertJsonCount(9, 'data')->assertJsonPath('summary.overdue', 9);
        $reads = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "sponsorship_deliverables"'));
        $this->assertCount(4, $reads);
        DB::disableQueryLog();
    }

    /**
     * @return array{Organization,User,Sponsor,array<string,mixed>,string} */
    private function scenario(bool $approved = false): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z'));
        $org = Organization::factory()->create();
        $author = $this->account($org, self::EDITOR);
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $this->actingAsWithMfa($author);
        $record = $this->postJson($this->agreementRoot($sponsor), ['reference' => 'campaign-agreement', 'title' => 'Accord campagne',
            'starts_on' => today()->format('Y-m-d'), 'ends_on' => today()->addMonth()->format('Y-m-d'), 'responsible_user_id' => $author->id])->assertCreated()->json('data');
        $scope = $this->postJson($this->agreementRoot($sponsor).'/'.$record['id'].'/exposures', ['agreement_version' => $record['version'],
            'target_type' => 'organization', 'target_id' => $org->id, 'channel' => 'social', 'authorization_reference' => 'Consentement de test'])->assertCreated()->json('data');
        $record['version'] = $scope['agreement_version'];
        if ($approved) {
            $terms = $this->patchJson($this->agreementRoot($sponsor).'/'.$record['id'].'/financial', ['agreement_version' => $record['version'], 'expected_version' => null,
                'terms' => ['kind' => 'in_kind', 'amount' => null, 'currency' => null, 'in_kind' => 'Matériel', 'payment_terms' => null]])->assertOk()->json('data');
            $this->actingAsWithMfa($this->account($org, self::EDITOR));
            $record = $this->postJson($this->agreementRoot($sponsor).'/'.$record['id'].'/transitions', ['version' => $terms['agreement_version'], 'target_status' => 'approved', 'confirm_authorizations' => true])->assertOk()->json('data');
            $this->actingAsWithMfa($author);
        }

        return [$org, $author, $sponsor, $record, $scope['id']];
    }

    /**
     * @param list<string> $permissions */
    private function account(Organization $org, array $permissions = ['partnerships.read', 'partnerships.write']): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);

        return $user;
    }

    private function agreementRoot(Sponsor $sponsor): string
    {
        return '/api/v1/tenants/'.$sponsor->organization_id.'/sponsors/'.$sponsor->id.'/agreements';
    }

    /**
     * @param array<string,mixed> $agreement */
    private function root(Sponsor $sponsor, array $agreement): string
    {
        return $this->agreementRoot($sponsor).'/'.$agreement['id'].'/campaigns';
    }

    /**
     * @param array<string,mixed> $agreement
     * @return array<string,mixed> */
    private function campaignPayload(array $agreement, User $author): array
    {
        return ['agreement_version' => $agreement['version'], 'title' => 'Campagne automne', 'starts_on' => today()->format('Y-m-d'),
            'ends_on' => today()->addMonth()->format('Y-m-d'), 'responsible_user_id' => $author->id];
    }

    /**
     * @param array<string,mixed> $agreement
     * @param array<string,mixed> $campaign
     * @return array<string,mixed> */
    private function promise(array $agreement, array $campaign, string $scope, User $author): array
    {
        return ['agreement_version' => $agreement['version'], 'campaign_version' => $campaign['version'], 'title' => 'Vidéo promise', 'kind' => 'video', 'exposure_id' => $scope,
            'due_at' => now()->subHours(2)->toIso8601String(), 'responsible_user_id' => $author->id];
    }

    /**
     * @param array<string,mixed> $agreement
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $record
     * @return array<string,mixed> */
    private function versions(array $agreement, array $campaign, array $record): array
    {
        return ['agreement_version' => $agreement['version'], 'campaign_version' => $campaign['version'], 'version' => $record['version']];
    }

    /**
     * @param array<string,mixed> $agreement
     * @return array{array<string,mixed>,array<string,mixed>} */
    private function prepared(Sponsor $sponsor, array $agreement, string $scope, User $author): array
    {
        $root = $this->root($sponsor, $agreement);
        $campaign = $this->postJson($root, $this->campaignPayload($agreement, $author))->assertCreated()->json('data');
        $promise = $this->postJson($root.'/'.$campaign['id'].'/deliverables', $this->promise($agreement, $campaign, $scope, $author))->assertCreated()->json('data');
        $campaign['version'] = $promise['campaign_version'];

        return [$campaign, $promise];
    }
}
