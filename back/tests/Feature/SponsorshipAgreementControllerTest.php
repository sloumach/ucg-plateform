<?php

namespace Tests\Feature;

use App\Modules\Contracts\Application\Contracts\PartnershipContractDirectory;
use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipOpportunity;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class SponsorshipAgreementControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    private const array EDITOR = ['partnerships.read', 'partnerships.write', 'partnerships.validate', 'financial.read', 'financial.write', 'financial.validate'];

    #[Group('tenant-isolation')]
    #[TestWith(['financial.read'])]
    #[TestWith(['financial.write'])]
    public function test_financial_permission_removal_rechecks_a_previously_read_agreement_without_changing_terms(string $permission): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $agreement = $this->prepared($org, $user, $sponsor);
        $path = $this->root($sponsor, $agreement['id']);
        $financial = $this->getJson($path.'/financial')->assertOk()->assertJsonPath('data.terms.amount', '12345.67')->json('data');
        $assignment = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail();
        $assignment->role->forceFill(['permissions' => array_values(array_diff(self::EDITOR, [$permission]))])->save();
        $before = DB::table('sponsorship_agreement_terms')->where('agreement_id', $agreement['id'])->firstOrFail();
        $version = DB::table('sponsorship_agreements')->where('id', $agreement['id'])->value('version');
        $events = DB::table('sponsorship_agreement_events')->count();
        $audits = DB::table('organization_audit_events')->count();

        $this->patchJson($path.'/financial', [...$this->financial($financial['agreement_version']), 'expected_version' => $financial['version']])->assertForbidden();
        $this->assertSame(get_object_vars($before), get_object_vars(DB::table('sponsorship_agreement_terms')->where('agreement_id', $agreement['id'])->firstOrFail()));
        $this->assertSame($version, DB::table('sponsorship_agreements')->where('id', $agreement['id'])->value('version'));
        $this->assertSame($events, DB::table('sponsorship_agreement_events')->count());
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
        if ($permission === 'financial.read') {
            $this->getJson($path.'/financial')->assertForbidden()->assertJsonMissingPath('data')->assertDontSee('IN_KIND_SECRET');
            $this->assertSame($events, DB::table('sponsorship_agreement_events')->count());
        } else {
            $this->getJson($path.'/financial')->assertOk()->assertJsonPath('data.version', $financial['version']);
            $this->assertSame($events + 1, DB::table('sponsorship_agreement_events')->count());
        }
        $this->getJson($path)->assertOk()->assertJsonMissingPath('data.terms');
    }

    public function test_anonymous_requests_are_rejected(): void
    {
        $sponsor = Sponsor::factory()->create();
        $root = $this->root($sponsor);
        $this->getJson($root)->assertUnauthorized();
        $this->postJson($root, [])->assertUnauthorized();
    }

    public function test_full_preparation_financial_approval_and_invalidation_flow(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $record = $this->postJson($this->root($sponsor), $this->payload($user))->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.responsible_label', $user->name)->assertJsonMissingPath('data.terms')->assertJsonMissingPath('data.contract_id')->json('data');
        $root = $this->root($sponsor, $record['id']);
        $exposure = $this->postJson($root.'/exposures', $this->scope($org, $record['version']))->assertCreated()
            ->assertJsonPath('data.exposure_authorized', false)->json('data');
        $financial = $this->patchJson($root.'/financial', $this->financial($exposure['agreement_version']))->assertOk()->json('data');
        $this->assertDatabaseHas('sponsorship_agreements', ['id' => $record['id'], 'status' => 'draft']);
        $stored = DB::table('sponsorship_agreement_terms')->where('agreement_id', $record['id'])->value('terms');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('12345.67', $stored);
        $this->assertStringNotContainsString('IN_KIND_SECRET', $stored);
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', false);
        $this->postJson($root.'/transitions', ['version' => $financial['agreement_version'], 'target_status' => 'approved', 'confirm_authorizations' => true])
            ->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_SELF_APPROVAL_FORBIDDEN');
        $approver = $this->account($org);
        $this->actingAsWithMfa($approver);
        $approved = $this->postJson($root.'/transitions', ['version' => $financial['agreement_version'], 'target_status' => 'approved', 'confirm_authorizations' => true])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $approver->id)->json('data');
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', true);
        $this->actingAsWithMfa($user);
        $changed = $this->patchJson($root, ['version' => $approved['version'], 'title' => 'Accord révisé'])->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.approved_by', null)->json('data');
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', false);
        $this->getJson($root.'/history?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
        $history = json_encode(DB::table('sponsorship_agreement_events')->get(), JSON_THROW_ON_ERROR);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('IN_KIND_SECRET', $history.$audit);
        $this->assertStringNotContainsString('12345.67', $history.$audit);
        $this->assertStringNotContainsString('CONSENT_SECRET', $history.$audit);
        $this->assertNotSame($approved['version'], $changed['version']);
    }

    public function test_partnership_only_access_cannot_read_or_mutate_finances_or_hr(): void
    {
        [$org, $editor, $sponsor] = $this->scenario();
        $record = $this->postJson($this->root($sponsor), $this->payload($editor))->assertCreated()->json('data');
        $root = $this->root($sponsor, $record['id']);
        $reader = $this->account($org, ['partnerships.read', 'partnerships.write']);
        $this->actingAsWithMfa($reader);
        $this->getJson($this->root($sponsor))->assertOk()->assertJsonMissingPath('data.0.terms')->assertJsonMissingPath('data.0.contract_id');
        $this->getJson($root)->assertOk()->assertJsonMissingPath('data.terms');
        $this->getJson($root.'/financial')->assertForbidden();
        $this->patchJson($root.'/financial', $this->financial($record['version']))->assertForbidden();
        $this->postJson($root.'/transitions', ['version' => $record['version'], 'target_status' => 'approved', 'confirm_authorizations' => true])->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/people')->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts')->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/sponsorship-references/contracts')->assertForbidden();
        $this->assertDatabaseCount('sponsorship_agreement_terms', 0);
    }

    public function test_public_reference_ports_do_not_expose_hr_or_foreign_records(): void
    {
        [$org] = $this->scenario();
        Person::factory()->create(['organization_id' => $org->id, 'status' => 'active', 'pseudonym' => 'PublicPseudo', 'first_name' => 'HR_SECRET', 'biography_internal' => 'HR_BIO']);
        Person::factory()->create(['organization_id' => $org->id, 'status' => 'former', 'pseudonym' => 'FORMER_HIDDEN']);
        Person::factory()->create(['status' => 'active', 'pseudonym' => 'FOREIGN_HIDDEN']);
        $team = Team::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        $root = '/api/v1/tenants/'.$org->id.'/sponsorship-references/';
        $response = $this->getJson($root.'players?per_page=1')->assertOk()->assertJsonPath('data.0.label', 'PublicPseudo')->assertJsonPath('meta.total', 1);
        $response->assertJsonMissingPath('data.0.first_name')->assertJsonMissingPath('data.0.contact_email');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('HR_SECRET', $content);
        $this->getJson($root.'teams')->assertOk()->assertJsonPath('data.0.id', $team->id);
        $this->getJson($root.'members')->assertOk()->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.roles');
    }

    #[Group('tenant-isolation')]
    public function test_cross_tenant_and_cross_sponsor_ids_never_resolve(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $foreign = SponsorshipAgreement::factory()->create();
        $this->grantPeopleAccess(Organization::findOrFail($foreign->organization_id), $user, self::EDITOR);
        $root = $this->root($sponsor, $foreign->id);
        $this->getJson($root)->assertNotFound();
        $this->patchJson($root, ['version' => $foreign->version, 'title' => 'Hijack'])->assertNotFound();
        foreach (['financial', 'history', 'exposures'] as $part) {
            $this->getJson($root.'/'.$part)->assertNotFound();
        }
        $this->postJson($root.'/exposures', $this->scope($org, $foreign->version))->assertNotFound();
        $this->patchJson($root.'/financial', $this->financial($foreign->version))->assertNotFound();
        $this->postJson($root.'/transitions', ['version' => $foreign->version, 'target_status' => 'archived'])->assertNotFound();
        $localOther = SponsorshipAgreement::factory()->create(['organization_id' => $org->id, 'sponsor_id' => Sponsor::factory()->create(['organization_id' => $org->id])->id]);
        $this->getJson($this->root($sponsor, $localOther->id))->assertNotFound();
    }

    public function test_foreign_players_teams_opportunities_and_unimplemented_targets_are_refused(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $record = $this->postJson($this->root($sponsor), $this->payload($user))->assertCreated()->json('data');
        $root = $this->root($sponsor, $record['id']);
        foreach (['team' => Team::factory()->create()->id, 'player' => Person::factory()->create(['status' => 'active'])->id] as $type => $id) {
            $this->postJson($root.'/exposures', [...$this->scope($org, $record['version']), 'target_type' => $type, 'target_id' => $id])->assertNotFound();
        }
        foreach (['event', 'content'] as $type) {
            $this->postJson($root.'/exposures', [...$this->scope($org, $record['version']), 'target_type' => $type])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_TARGET_UNAVAILABLE');
        }
        $foreignOpportunity = SponsorshipOpportunity::query()->forceCreate(['organization_id' => $org->id,
            'sponsor_id' => Sponsor::factory()->create(['organization_id' => $org->id])->id, 'title' => 'Autre dossier', 'status' => 'open', 'version' => (string) Str::uuid()]);
        $this->postJson($this->root($sponsor), [...$this->payload($user), 'reference' => 'other-agreement', 'opportunity_id' => $foreignOpportunity->id])->assertNotFound();
        $this->assertDatabaseCount('sponsorship_exposures', 0);
    }

    public function test_revision_dates_and_unknown_fields_are_validated(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $root = $this->root($sponsor);
        $this->postJson($root, [...$this->payload($user), 'ends_on' => '2020-01-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $record = $this->postJson($root, $this->payload($user))->assertCreated()->json('data');
        $this->patchJson($root.'/'.$record['id'], ['version' => (string) Str::uuid(), 'title' => 'Stale'])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_VERSION_STALE');
        $this->patchJson($root.'/'.$record['id'], ['version' => $record['version'], 'approved_by' => $user->id, 'status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors(['approved_by', 'status']);
        $this->postJson($root, $this->payload($user))->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_REFERENCE_EXISTS');
        $this->getJson($root.'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_stale_parent_version_cannot_save_scopes_or_financial_data(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $record = $this->postJson($this->root($sponsor), $this->payload($user))->assertCreated()->json('data');
        $root = $this->root($sponsor, $record['id']);
        $this->postJson($root.'/exposures', $this->scope($org, (string) Str::uuid()))->assertConflict();
        $this->patchJson($root.'/financial', $this->financial((string) Str::uuid()))->assertConflict();
        $this->assertDatabaseCount('sponsorship_exposures', 0);
        $this->assertDatabaseCount('sponsorship_agreement_terms', 0);
    }

    public function test_financial_read_is_audited_and_returns_503_if_audit_fails(): void
    {
        [, $user, $sponsor] = $this->scenario();
        $record = $this->postJson($this->root($sponsor), $this->payload($user))->assertCreated()->json('data');
        $root = $this->root($sponsor, $record['id']);
        $this->getJson($root.'/financial')->assertOk()->assertJsonPath('data.terms', null);
        $this->assertDatabaseHas('sponsorship_agreement_events', ['agreement_id' => $record['id'], 'action' => 'financial_viewed']);
    }

    public function test_financial_audit_failure_returns_503_without_a_business_payload(): void
    {
        [, $user, $sponsor] = $this->scenario();
        $record = $this->postJson($this->root($sponsor), $this->payload($user))->assertCreated()->json('data');
        $root = $this->root($sponsor, $record['id']);
        $repository = $this->mock(SponsorshipAgreementRepository::class);
        $repository->shouldReceive('find')->andReturn(SponsorshipAgreement::findOrFail($record['id']));
        $repository->shouldReceive('terms')->andReturn(null);
        $repository->shouldReceive('event')->andThrow(new \RuntimeException('Audit failed'));
        $this->getJson($root.'/financial')->assertStatus(503)->assertJsonPath('code', 'ACCESS_AUDIT_UNAVAILABLE')->assertJsonMissingPath('data');
    }

    public function test_contract_link_requires_administrative_read_and_does_not_grant_it(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $contract = ContractRecord::factory()->create(['organization_id' => $org->id, 'responsible_person_id' => Person::factory()->create(['organization_id' => $org->id])->id, 'type' => 'sponsor']);
        $this->postJson($this->root($sponsor), [...$this->payload($user), 'contract_id' => $contract->id])->assertForbidden();
        $admin = $this->account($org, [...self::EDITOR, 'administrative.read']);
        $this->actingAsWithMfa($admin);
        $record = $this->postJson($this->root($sponsor), [...$this->payload($admin), 'contract_id' => $contract->id])->assertCreated()->assertJsonPath('data.contract_id', $contract->id)->json('data');
        $this->actingAsWithMfa($user);
        $this->getJson($this->root($sponsor, $record['id']))->assertOk()->assertJsonMissingPath('data.contract_id')->assertJsonMissingPath('data.contract_version');
    }

    #[Group('tenant-isolation')]
    public function test_exposure_authorization_disappears_if_responsible_access_is_revoked(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $record = $this->prepared($org, $user, $sponsor);
        $this->actingAsWithMfa($this->account($org));
        $root = $this->root($sponsor, $record['id']);
        $this->postJson($root.'/transitions', ['version' => $record['version'], 'target_status' => 'approved', 'confirm_authorizations' => true])->assertOk();
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', true);
        OrganizationRoleAssignment::query()->where('user_id', $user->id)->update(['status' => 'suspended', 'version' => (string) Str::uuid()]);
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', false);
    }

    public function test_exclusive_categories_cannot_overlap_even_for_other_subjects(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        $first = $this->prepared($org, $user, $sponsor, 'exclusive-a', 'hardware');
        $second = $this->prepared($org, $user, $sponsor, 'exclusive-b', 'hardware');
        $this->actingAsWithMfa($this->account($org));
        $this->postJson($this->root($sponsor, $first['id']).'/transitions', ['version' => $first['version'], 'target_status' => 'approved', 'confirm_authorizations' => true])->assertOk();
        $this->postJson($this->root($sponsor, $second['id']).'/transitions', ['version' => $second['version'], 'target_status' => 'approved', 'confirm_authorizations' => true])->assertConflict()->assertJsonPath('code', 'SPONSORSHIP_EXCLUSIVITY_CONFLICT');
    }

    public function test_active_sponsor_contract_is_checked_using_its_calendar_and_any_contract_revision_blocks_exposure(): void
    {
        [$org, , $sponsor] = $this->scenario();
        $org->forceFill(['timezone' => 'Europe/Paris'])->save();
        $rights = [...self::EDITOR, 'administrative.read', 'administrative.write', 'administrative.validate'];
        $preparer = $this->account($org, $rights);
        $approver = $this->account($org, $rights);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $org->id]);
        $contract = ContractRecord::factory()->create(['organization_id' => $org->id, 'responsible_person_id' => $person->id,
            'type' => 'sponsor', 'created_by' => $preparer->id, 'prepared_by' => $preparer->id,
            'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString()]);
        ContractParty::query()->forceCreate(['organization_id' => $org->id, 'contract_id' => $contract->id,
            'kind' => 'external', 'name' => 'Société sponsor', 'person_id' => null, 'position' => 1]);
        $service = app(ContractService::class);
        $service->transition($this->personContext($org, $preparer), $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
        foreach (['approved', 'sent', 'signed', 'active'] as $status) {
            $contract->refresh();
            $service->transition($this->personContext($org, $approver), $contract->id, ['version' => $contract->version, 'status' => $status,
                ...($status === 'signed' ? ['signed_on' => today()->toDateString(), 'signature_reference' => 'Original externe', 'confirm_signature_received' => true] : [])]);
        }
        $contract->refresh();
        $directory = app(PartnershipContractDirectory::class);
        $this->assertTrue($directory->isCurrent($this->personContext($org, $preparer), $contract->id, $contract->version));
        $this->actingAsWithMfa($preparer);
        $record = $this->postJson($this->root($sponsor), [...$this->payload($preparer), 'contract_id' => $contract->id])->assertCreated()->json('data');
        $root = $this->root($sponsor, $record['id']);
        $scope = $this->postJson($root.'/exposures', $this->scope($org, $record['version']))->assertCreated()->json('data');
        $terms = $this->patchJson($root.'/financial', $this->financial($scope['agreement_version']))->assertOk()->json('data');
        $this->actingAsWithMfa($approver);
        $this->postJson($root.'/transitions', ['version' => $terms['agreement_version'], 'target_status' => 'approved', 'confirm_authorizations' => true])->assertOk();
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', true);
        $contract->forceFill(['version' => (string) Str::uuid()])->save();
        $this->getJson($root.'/exposures')->assertOk()->assertJsonPath('data.0.exposure_authorized', false);
    }

    public function test_agreement_search_pagination_and_labels_do_not_add_queries_per_row(): void
    {
        [$org, $user, $sponsor] = $this->scenario();
        SponsorshipAgreement::factory()->count(8)->create(['organization_id' => $org->id, 'sponsor_id' => $sponsor->id,
            'responsible_user_id' => $user->id, 'prepared_by' => $user->id]);
        $this->getJson($this->root($sponsor).'?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 8);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->root($sponsor).'?per_page=8')->assertOk()->assertJsonCount(8, 'data');
        $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'sponsorship_agreements'));
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->getJson($this->root($sponsor).'?q=absent')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->root($sponsor).'?status=approved')->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidTerms(): array
    {
        return ['float' => [['amount' => 123.45]], 'leading zero' => [['amount' => '01.00']], 'no currency' => [['currency' => null]],
            'zero cash' => [['amount' => '0.00']], 'no in kind' => [['in_kind' => null]], 'unknown field' => [['bank_account' => 'SECRET']],
            'kind' => [['kind' => 'unsupported']]];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidTerms')]
    public function test_financial_payloads_reject_ambiguous_or_inconsistent_values(array $values): void
    {
        [, $user, $sponsor] = $this->scenario();
        $record = $this->postJson($this->root($sponsor), $this->payload($user))->assertCreated()->json('data');
        $body = $this->financial($record['version']);
        $body['terms'] = [...$body['terms'], ...$values];
        $this->patchJson($this->root($sponsor, $record['id']).'/financial', $body)->assertUnprocessable();
        $this->assertDatabaseCount('sponsorship_agreement_terms', 0);
    }

    /** @return array{Organization, User, Sponsor} */
    private function scenario(): array
    {
        $this->freezeTime();
        $org = Organization::factory()->create();
        $user = $this->account($org);
        $this->actingAsWithMfa($user);

        return [$org, $user, Sponsor::factory()->create(['organization_id' => $org->id])];
    }

    /** @param list<string> $permissions */
    private function account(Organization $org, array $permissions = self::EDITOR): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);

        return $user;
    }

    private function root(Sponsor $sponsor, ?string $id = null): string
    {
        return '/api/v1/tenants/'.$sponsor->organization_id.'/sponsors/'.$sponsor->id.'/agreements'.($id === null ? '' : '/'.$id);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return ['reference' => 'agreement-pilot', 'title' => 'Accord matériel', 'starts_on' => today()->format('Y-m-d'),
            'ends_on' => today()->addMonth()->format('Y-m-d'), 'responsible_user_id' => $user->id];
    }

    /** @return array<string, mixed> */
    private function scope(Organization $org, string $version): array
    {
        return ['agreement_version' => $version, 'target_type' => 'organization', 'target_id' => $org->id,
            'channel' => 'website', 'authorization_reference' => 'CONSENT_SECRET'];
    }

    /** @return array<string, mixed> */
    private function financial(string $version): array
    {
        return ['agreement_version' => $version, 'expected_version' => null, 'terms' => [
            'kind' => 'mixed', 'currency' => 'EUR', 'amount' => '12345.67', 'in_kind' => 'IN_KIND_SECRET', 'payment_terms' => null]];
    }

    /** @return array<string, mixed> */
    private function prepared(Organization $org, User $user, Sponsor $sponsor, string $reference = 'prepared', ?string $exclusive = null): array
    {
        $record = $this->postJson($this->root($sponsor), [...$this->payload($user), 'reference' => $reference])->assertCreated()->json('data');
        $root = $this->root($sponsor, $record['id']);
        $exposure = $this->postJson($root.'/exposures', [...$this->scope($org, $record['version']), 'exclusivity_key' => $exclusive])->assertCreated()->json('data');
        $terms = $this->patchJson($root.'/financial', $this->financial($exposure['agreement_version']))->assertOk()->json('data');

        return [...$record, 'version' => $terms['agreement_version']];
    }
}
