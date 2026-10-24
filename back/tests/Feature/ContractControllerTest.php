<?php

namespace Tests\Feature;

use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guest_and_sporting_staff_cannot_read_contracts(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org))->assertUnauthorized();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['sports.read', 'teams.manage']);
        $this->actingAsWithMfa($user);
        $this->getJson($this->path($org))->assertForbidden();
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_creation_encrypts_content_and_exposes_no_financial_fields_or_sporting_authorization(): void
    {
        [$org, $person] = $this->editor();
        $response = $this->postJson($this->path($org), $this->payload($person))->assertCreated()
            ->assertJsonPath('data.title', 'Dossier confidentiel')->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.responsible_name', 'Camille Martin')->assertJsonPath('data.parties_count', 1)
            ->assertJsonPath('data.pending_obligations_count', 1)->assertJsonPath('data.in_force', false)
            ->assertJsonPath('data.sporting_eligibility', 'not_assessed')->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.amount')->assertJsonPath('notification.message', 'Le dossier de contrat a été enregistré.');
        $row = DB::table('contracts')->where('id', $response->json('data.id'))->first();
        $this->assertNotNull($row);
        $this->assertNotSame('Dossier confidentiel', $row->title);
        $this->assertNotSame('Camille Martin', $row->responsible_name);
        $this->assertNotSame('Partie privée', DB::table('contract_parties')->value('name'));
        $this->assertDatabaseCount('contract_transitions', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $this->assertStringNotContainsString('Dossier confidentiel', json_encode(DB::table('organization_audit_events')->first(), JSON_THROW_ON_ERROR));
    }

    #[Group('tenant-isolation')]
    public function test_foreign_contracts_and_foreign_parties_return_404_without_partial_writes(): void
    {
        [$org, $person] = $this->editor();
        $foreign = ContractRecord::factory()->create();
        $this->getJson($this->path($org, $foreign->id))->assertNotFound();
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'title' => 'Hijack'])->assertNotFound();
        $this->postJson($this->path($org, $foreign->id).'/transitions', ['version' => $foreign->version, 'status' => 'in_review'])->assertNotFound();
        $this->getJson($this->path($org, $foreign->id).'/transitions')->assertNotFound();
        $other = Person::factory()->create();
        $this->postJson($this->path($org), [...$this->payload($person), 'responsible_person_id' => $other->id])->assertNotFound();
        $this->postJson($this->path($org), [...$this->payload($person), 'parties' => [['kind' => 'person', 'person_id' => $other->id, 'name' => 'Foreign']]])->assertNotFound();
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_the_workflow_requires_distinct_approval_then_records_signature_activation_and_termination(): void
    {
        [$org, $person] = $this->editor();
        $record = $this->postJson($this->path($org), $this->payload($person))->assertCreated()->json('data');
        $record = $this->move($org, $record, 'in_review');
        $this->postJson($this->path($org, $record['id']).'/transitions', ['version' => $record['version'], 'status' => 'approved'])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_SELF_APPROVAL_FORBIDDEN');
        $approver = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $approver, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $this->actingAsWithMfa($approver);
        $record = $this->move($org, $record, 'approved');
        $this->assertSame($approver->id, $record['approved_by']);
        $record = $this->move($org, $record, 'sent');
        $this->postJson($this->path($org, $record['id']).'/transitions', ['version' => $record['version'], 'status' => 'signed'])
            ->assertUnprocessable()->assertJsonValidationErrors('signed_on');
        $record = $this->move($org, $record, 'signed', ['signed_on' => now()->utc()->toDateString(), 'signature_reference' => 'Original reçu', 'confirm_signature_received' => true]);
        $this->assertFalse($record['in_force']);
        $this->assertSame('declared_external', $record['signature_evidence_status']);
        $record = $this->move($org, $record, 'active');
        $this->assertTrue($record['in_force']);
        $this->assertSame('not_assessed', $record['sporting_eligibility']);
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'title' => 'Rewrite signed terms'])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_TERMS_FROZEN');
        $record = $this->move($org, $record, 'terminated', ['terminated_on' => now()->utc()->toDateString()]);
        $this->postJson($this->path($org, $record['id']).'/transitions', ['version' => $record['version'], 'status' => 'archived'])
            ->assertConflict()->assertJsonPath('code', 'CONTRACT_PENDING_OBLIGATIONS');
        $item = $record['obligations'][0];
        $record = $this->patchJson($this->path($org, $record['id']).'/obligations/'.$item['id'], [
            'version' => $item['version'], 'contract_version' => $record['version'], 'status' => 'waived',
        ])->assertOk()->json('data');
        $record = $this->move($org, $record, 'archived');
        $this->getJson($this->path($org, $record['id']))->assertOk()->assertJsonPath('data.signature_reference', 'Original reçu');
        $this->getJson($this->path($org, $record['id']).'/transitions?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 8);
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'title' => 'Restore'])->assertConflict()->assertJsonPath('code', 'CONTRACT_ARCHIVED');
        $this->assertDatabaseCount('contract_transitions', 8);
    }

    public function test_returning_to_review_unlocks_terms_and_clears_approval(): void
    {
        [$org, $person] = $this->editor();
        $record = $this->postJson($this->path($org), $this->payload($person))->assertCreated()->json('data');
        $record = $this->move($org, $record, 'in_review');
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $this->actingAsWithMfa($user);
        $record = $this->move($org, $record, 'approved');
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'starts_on' => '2026-01-02'])->assertConflict();
        $record = $this->move($org, $record, 'in_review');
        $this->assertNull($record['approved_by']);
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'title' => 'Version préparée'])
            ->assertOk()->assertJsonPath('data.prepared_by', $user->id)->assertJsonPath('data.approved_by', null);
    }

    public function test_dated_signature_does_not_activate_a_future_contract(): void
    {
        [$org, $person] = $this->editor();
        $record = $this->postJson($this->path($org), [...$this->payload($person), 'starts_on' => now()->utc()->addDays(10)->toDateString(), 'ends_on' => null])->assertCreated()->json('data');
        $record = $this->move($org, $record, 'in_review');
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['administrative.read', 'administrative.write', 'administrative.validate']);
        $this->actingAsWithMfa($user);
        $record = $this->move($org, $record, 'approved');
        $record = $this->move($org, $record, 'sent');
        $record = $this->move($org, $record, 'signed', ['signed_on' => now()->utc()->toDateString(), 'signature_reference' => 'Original', 'confirm_signature_received' => true]);
        $this->postJson($this->path($org, $record['id']).'/transitions', ['version' => $record['version'], 'status' => 'active'])->assertConflict()->assertJsonPath('code', 'CONTRACT_NOT_EFFECTIVE');
    }

    #[Group('tenant-isolation')]
    public function test_options_and_lists_are_paginated_and_do_not_expose_other_tenants_or_private_contact_fields(): void
    {
        [$org, $person] = $this->editor();
        Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $org->id]);
        Person::factory()->withAdministrativeIdentity()->create(['first_name' => 'FOREIGN']);
        $this->getJson($this->path($org).'/people?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.contact_email')->assertJsonMissingPath('data.0.pseudonym');
        $this->postJson($this->path($org), $this->payload($person))->assertCreated();
        $this->getJson($this->path($org).'?per_page=1&q=contrat')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.parties');
        $this->getJson($this->path($org).'?per_page=101')->assertUnprocessable();
        $this->getJson($this->path($org).'?q=%25')->assertUnprocessable();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return ['tenant' => [['organization_id' => 'spoof'], 'organization_id'], 'financial' => [['amount' => 5000], 'amount'],
            'status injection' => [['status' => 'signed'], 'status'], 'legal proof' => [['signed_version_id' => (string) Str::uuid()], 'signed_version_id'],
            'title length' => [['title' => str_repeat('a', 161)], 'title'], 'reference' => [['reference' => 'Bad ref'], 'reference'],
            'type' => [['type' => 'payroll'], 'type'], 'parties empty' => [['parties' => []], 'parties'],
            'party unknown' => [['parties' => [['kind' => 'external', 'name' => 'X', 'email' => 'private@test.test']]], 'parties.0'],
            'end without start' => [['starts_on' => null, 'ends_on' => '2026-12-31'], 'ends_on'],
            'date order' => [['starts_on' => '2026-12-31', 'ends_on' => '2026-01-01'], 'ends_on'],
            'too many obligations' => [['obligations' => array_fill(0, 51, ['title' => 'Obligation'])], 'obligations'],
            'obligation status spoof' => [['obligations' => [['title' => 'X', 'status' => 'completed']]], 'obligations.0']];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidInputs')]
    public function test_invalid_payload_returns_422_without_partial_records(array $changes, string $field): void
    {
        [$org, $person] = $this->editor();
        $this->postJson($this->path($org), [...$this->payload($person), ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_mfa_and_tenant_confirmation_are_required(): void
    {
        [$org, $person] = $this->editor();
        $user = User::findOrFail(auth()->id());
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($this->path($org), $this->payload($person))->assertForbidden();
        $this->actingAsWithMfa($user);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $org->id, 'actor_user_id' => $user->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->postJson($this->path($org), $this->payload($person))->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_retained_people_can_be_referenced_without_reactivating_access_or_inventing_sporting_eligibility(): void
    {
        [$org, $person] = $this->editor();
        $former = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $org->id, 'status' => 'former']);
        $this->postJson($this->path($org), [...$this->payload($person), 'parties' => [['kind' => 'person', 'person_id' => $former->id, 'name' => 'Ancienne partie']]])
            ->assertCreated()->assertJsonPath('data.parties.0.person_id', $former->id)->assertJsonPath('data.sporting_eligibility', 'not_assessed');
        $this->assertDatabaseHas('people', ['id' => $former->id, 'status' => 'former']);
    }

    public function test_invalid_transition_types_return_422_and_never_change_the_dossier(): void
    {
        [$org, $person] = $this->editor();
        $record = $this->postJson($this->path($org), $this->payload($person))->assertCreated()->json('data');
        $this->postJson($this->path($org, $record['id']).'/transitions', ['version' => $record['version'], 'status' => ['signed']])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('contracts', ['id' => $record['id'], 'status' => 'draft']);
        $this->assertDatabaseCount('contract_transitions', 1);
    }

    /** @param list<string> $permissions
     * @return array{Organization, Person}
     */
    private function editor(array $permissions = ['administrative.read', 'administrative.write', 'administrative.validate']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return [$org, Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $org->id])];
    }

    private function path(Organization $org, ?string $id = null): string
    {
        return '/api/v1/tenants/'.$org->id.'/contracts'.($id === null ? '' : '/'.$id);
    }

    /** @return array<string, mixed> */
    private function payload(Person $person): array
    {
        return ['reference' => 'contrat-pilote', 'type' => 'player', 'title' => 'Dossier confidentiel',
            'responsible_person_id' => $person->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
            'parties' => [['kind' => 'person', 'person_id' => $person->id, 'name' => 'Partie privée']],
            'obligations' => [['title' => 'Obligation privée', 'due_on' => '2026-12-01']]];
    }

    /** @param array<string, mixed> $record
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function move(Organization $org, array $record, string $status, array $extra = []): array
    {
        return $this->postJson($this->path($org, $record['id']).'/transitions', ['version' => $record['version'], 'status' => $status, ...$extra])
            ->assertOk()->assertJsonPath('data.status', $status)->json('data');
    }
}
