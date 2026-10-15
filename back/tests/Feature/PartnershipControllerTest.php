<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Application\Services\SponsorService;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PartnershipControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guests_cannot_read_or_write(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org))->assertUnauthorized();
        $this->postJson($this->path($org), ['name' => 'Prospect'])->assertUnauthorized();
    }

    public function test_create_search_contact_opportunity_and_manual_exchange_are_private_and_do_not_create_accounts_or_send_mail(): void
    {
        $org = $this->editor();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        $users = DB::table('users')->count();
        $memberships = DB::table('organization_memberships')->count();
        $sponsor = $this->postJson($this->path($org), ['name' => 'Société exemple', 'industry' => 'Matériel'])
            ->assertCreated()->assertJsonPath('notification.message', 'La fiche partenaire a été enregistrée.')
            ->assertJsonPath('data.publication_status', 'private')->assertJsonMissingPath('data.organization_id')->json('data');
        $root = $this->path($org, $sponsor['id']);
        $contact = $this->postJson($root.'/contacts', ['name' => 'CONTACT_PRIVATE', 'email' => 'commercial@example.test'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
        $opportunity = $this->postJson($root.'/opportunities', ['title' => 'Campagne été'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonMissingPath('data.amount')->json('data');
        $this->patchJson($root.'/opportunities/'.$opportunity['id'], ['version' => $opportunity['version'], 'status' => 'negotiating'])
            ->assertOk()->assertJsonPath('data.status', 'negotiating');
        $this->postJson($root.'/interactions', ['channel' => 'email', 'summary' => 'EXCHANGE_PRIVATE', 'occurred_at' => '2026-01-01T12:00:00+00:00',
            'contact_id' => $contact['id'], 'opportunity_id' => $opportunity['id']])->assertCreated()->assertJsonPath('data.created_by', auth()->id());
        $this->getJson($root.'/interactions?per_page=1&page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson($this->path($org).'?q=Société&per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('sponsor_contacts', ['sponsor_id' => $sponsor['id'], 'organization_id' => $org->id, 'email' => 'commercial@example.test']);
        $this->assertDatabaseCount('organization_audit_events', 5);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CONTACT_PRIVATE', $audit);
        $this->assertStringNotContainsString('EXCHANGE_PRIVATE', $audit);
        $this->assertStringNotContainsString('commercial@example.test', $audit);
        $this->assertDatabaseCount('users', $users);
        $this->assertDatabaseCount('organization_memberships', $memberships);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_search_is_tenant_scoped_paginated_and_wildcards_are_literal(): void
    {
        $org = $this->editor();
        Sponsor::factory()->create(['organization_id' => $org->id, 'name' => 'Alpha%One']);
        Sponsor::factory()->create(['organization_id' => $org->id, 'name' => 'AlphaTwo']);
        Sponsor::factory()->create(['name' => 'Alpha%Foreign']);
        $this->getJson($this->path($org).'?q=Alpha&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->path($org).'?q=Alpha%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha%One');
        $this->getJson($this->path($org).'?q=Absent')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($org).'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_reader_cannot_write_and_partnership_permissions_do_not_open_hr_contracts_or_sports(): void
    {
        $org = $this->editor(['partnerships.read']);
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $root = $this->path($org, $sponsor->id);
        $this->getJson($root)->assertOk();
        $this->postJson($this->path($org), ['name' => 'Denied'])->assertForbidden();
        $this->patchJson($root, ['version' => $sponsor->version, 'name' => 'Denied'])->assertForbidden();
        foreach (['contacts', 'opportunities', 'interactions'] as $kind) {
            $this->getJson($root.'/'.$kind)->assertOk();
            $this->postJson($root.'/'.$kind, [])->assertForbidden();
        }
        $this->getJson('/api/v1/tenants/'.$org->id.'/people')->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts')->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/teams')->assertForbidden();
    }

    public function test_an_editor_cannot_read_hr_and_an_owner_has_no_implicit_partnership_rights(): void
    {
        $org = $this->editor();
        $this->getJson('/api/v1/tenants/'.$org->id.'/people')->assertForbidden();
        $this->getJson('/api/v1/tenants/'.$org->id.'/contracts')->assertForbidden();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));
        $this->getJson($this->path($org))->assertForbidden();
        $this->postJson($this->path($org), ['name' => 'Denied'])->assertForbidden();
    }

    public function test_cross_tenant_and_cross_sponsor_references_return_404_even_with_access_to_both_tenants(): void
    {
        $org = $this->editor();
        $local = Sponsor::factory()->create(['organization_id' => $org->id]);
        $other = Sponsor::factory()->create(['organization_id' => $org->id]);
        $foreign = Sponsor::factory()->create();
        $this->grantPeopleAccess(Organization::findOrFail($foreign->organization_id), User::findOrFail(auth()->id()), ['partnerships.read', 'partnerships.write']);
        $this->getJson($this->path($org, $foreign->id))->assertNotFound();
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'name' => 'Hijack'])->assertNotFound();
        foreach (['contacts' => ['name' => 'Contact'], 'opportunities' => ['title' => 'Offre']] as $kind => $payload) {
            $record = $this->postJson($this->path($org, $other->id).'/'.$kind, $payload)->assertCreated()->json('data');
            $this->patchJson($this->path($org, $local->id).'/'.$kind.'/'.$record['id'], ['version' => $record['version'], ...$payload])->assertNotFound();
            $this->postJson($this->path($org, $local->id).'/interactions', [...$this->exchange(),
                $kind === 'contacts' ? 'contact_id' : 'opportunity_id' => $record['id']])->assertNotFound();
            $this->getJson($this->path($org, $foreign->id).'/'.$kind)->assertNotFound();
            $this->postJson($this->path($org, $foreign->id).'/'.$kind, $payload)->assertNotFound();
        }
        $this->getJson($this->path($org, $foreign->id).'/interactions')->assertNotFound();
        $this->postJson($this->path($org, $foreign->id).'/interactions', $this->exchange())->assertNotFound();
        $this->assertDatabaseCount('sponsorship_interactions', 0);
    }

    public function test_updates_require_versions_and_archiving_preserves_but_freezes_the_file(): void
    {
        $org = $this->editor();
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $root = $this->path($org, $sponsor->id);
        $this->patchJson($root, ['name' => 'Changed'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson($root, ['version' => (string) Str::uuid(), 'name' => 'Changed'])->assertConflict()->assertJsonPath('code', 'PARTNERSHIP_VERSION_STALE');
        $this->patchJson($root, ['version' => $sponsor->version])->assertUnprocessable();
        $this->postJson($root.'/interactions', $this->exchange())->assertCreated();
        $archived = $this->patchJson($root, ['version' => $sponsor->version, 'status' => 'archived'])->assertOk()->json('data');
        $this->patchJson($root, ['version' => $archived['version'], 'name' => 'Changed'])->assertConflict()->assertJsonPath('code', 'PARTNERSHIP_ARCHIVED');
        $this->postJson($root.'/contacts', ['name' => 'Contact'])->assertConflict();
        $this->postJson($root.'/interactions', $this->exchange())->assertConflict();
        $this->getJson($root.'/interactions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_child_versions_archiving_and_search_are_enforced(): void
    {
        $org = $this->editor();
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $root = $this->path($org, $sponsor->id);
        foreach (['contacts' => ['name' => 'Alpha'], 'opportunities' => ['title' => 'Alpha']] as $kind => $payload) {
            $record = $this->postJson($root.'/'.$kind, $payload)->assertCreated()->json('data');
            $this->getJson($root.'/'.$kind.'?q=Al&per_page=1')->assertOk()->assertJsonCount(1, 'data');
            $this->patchJson($root.'/'.$kind.'/'.$record['id'], ['version' => (string) Str::uuid(), ...$payload])->assertConflict();
            $archived = $this->patchJson($root.'/'.$kind.'/'.$record['id'], ['version' => $record['version'], 'status' => 'archived'])->assertOk()->json('data');
            $this->patchJson($root.'/'.$kind.'/'.$record['id'], ['version' => $archived['version'], ...$payload])->assertConflict();
            $this->postJson($root.'/interactions', [...$this->exchange(), $kind === 'contacts' ? 'contact_id' : 'opportunity_id' => $record['id']])->assertConflict();
        }
    }

    public function test_no_implicit_company_or_agreement_is_created_when_opportunity_is_won(): void
    {
        $org = $this->editor();
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $this->postJson($this->path($org, $sponsor->id).'/opportunities', ['title' => 'Déclaration', 'status' => 'won'])->assertCreated();
        $this->assertDatabaseHas('sponsors', ['id' => $sponsor->id, 'status' => 'prospect']);
        $this->assertDatabaseCount('contracts', 0);
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return [
            'blank name' => ['sponsors', ['name' => ' '], 'name'],
            'unknown tenant' => ['sponsors', ['name' => 'A', 'organization_id' => 'forged'], 'organization_id'],
            'too long' => ['sponsors', ['name' => str_repeat('a', 161)], 'name'],
            'status' => ['sponsors', ['name' => 'A', 'status' => 'unknown'], 'status'],
            'email' => ['contacts', ['name' => 'A', 'email' => 'invalid'], 'email'],
            'phone' => ['contacts', ['name' => 'A', 'phone' => '<script>'], 'phone'],
            'account link' => ['contacts', ['name' => 'A', 'user_id' => 1], 'user_id'],
            'hr link' => ['contacts', ['name' => 'A', 'person_id' => 'forged'], 'person_id'],
            'amount' => ['opportunities', ['title' => 'A', 'amount' => 1000], 'amount'],
            'blank title' => ['opportunities', ['title' => null], 'title'],
            'exchange author' => ['interactions', ['channel' => 'note', 'summary' => 'A', 'occurred_at' => '2026-01-01T00:00:00+00:00', 'created_by' => 1], 'created_by'],
            'future exchange' => ['interactions', ['channel' => 'note', 'summary' => 'A', 'occurred_at' => '2099-01-01T00:00:00+00:00'], 'occurred_at'],
            'bad channel' => ['interactions', ['channel' => 'unknown', 'summary' => 'A', 'occurred_at' => '2026-01-01T00:00:00+00:00'], 'channel'],
            'blank summary' => ['interactions', ['channel' => 'note', 'summary' => ' ', 'occurred_at' => '2026-01-01T00:00:00+00:00'], 'summary'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidInputs')]
    public function test_invalid_payloads_return_localized_422_without_writes(string $kind, array $payload, string $field): void
    {
        $org = $this->editor();
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $root = $kind === 'sponsors' ? $this->path($org) : $this->path($org, $sponsor->id).'/'.$kind;
        $this->postJson($root, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('organization_audit_events', 0);
        $this->assertDatabaseCount('sponsor_contacts', 0);
        $this->assertDatabaseCount('sponsorship_opportunities', 0);
        $this->assertDatabaseCount('sponsorship_interactions', 0);
    }

    public function test_revoked_permissions_and_suspended_tenants_are_rechecked_in_services(): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        $context = $this->personContext($org, $user);
        $assignment = OrganizationRoleAssignment::query()->where('user_id', $user->id)->firstOrFail();
        $assignment->forceFill(['status' => 'suspended'])->save();
        $this->getJson($this->path($org))->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(SponsorService::class)->save($context, ['name' => 'Denied']);
    }

    public function test_query_count_is_bounded_when_listing_many_contacts(): void
    {
        $org = $this->editor();
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $root = $this->path($org, $sponsor->id).'/contacts';
        for ($i = 0; $i < 8; $i++) {
            $this->postJson($root, ['name' => 'Contact '.$i])->assertCreated();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($root.'?per_page=8')->assertOk()->assertJsonCount(8, 'data');
        $queries = array_filter(DB::getQueryLog(), fn (array $entry): bool => str_contains($entry['query'], 'sponsor_contacts'));
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
    }

    public function test_mfa_proof_and_current_confirmed_tenant_are_required(): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->getJson($this->path($org))->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->actingAsWithMfa($user);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => [
            'organization_id' => $org->id, 'actor_user_id' => $user->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->postJson($this->path($org), ['name' => 'Denied'])->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('sponsors', 0);
    }

    public function test_partner_permissions_can_be_provisioned_by_the_owner_and_are_audited_without_granting_them_to_the_owner(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));
        $root = '/api/v1/tenants/'.$org->id;
        $response = $this->postJson($root.'/roles', ['key' => 'partnership-manager', 'name' => 'Partenariats', 'active' => true,
            'permissions' => ['partnerships.read', 'partnerships.write']])->assertCreated()->assertJsonPath('data.privileged', true);
        $this->assertDatabaseHas('organization_roles', ['id' => $response->json('data.id'), 'organization_id' => $org->id]);
        $this->assertDatabaseCount('organization_access_events', 1);
        $this->getJson($this->path($org))->assertForbidden();
    }

    public function test_history_filters_are_bounded_and_read_responses_are_not_shared_cacheable(): void
    {
        $org = $this->editor();
        $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
        $root = $this->path($org, $sponsor->id);
        $this->postJson($root.'/interactions', $this->exchange())->assertCreated();
        $this->postJson($root.'/interactions', [...$this->exchange(), 'channel' => 'phone'])->assertCreated();
        $this->getJson($root.'/interactions?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $response = $this->getJson($root.'/interactions?channel=phone')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'phone');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->getJson($root.'/interactions?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson($root.'/interactions?sort=summary%3BDROP')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson($root.'/contacts?organization_id=forged')->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->patchJson($root.'/interactions/'.(string) Str::uuid(), ['summary' => 'Overwrite'])->assertNotFound();
        $this->deleteJson($root)->assertStatus(405);
    }

    public function test_audit_failure_rolls_back_business_writes(): void
    {
        $org = $this->editor();
        $this->mock(TenantAuditJournal::class)
            ->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->path($org), ['name' => 'Atomic company'])->assertStatus(500);
        $this->assertDatabaseCount('sponsors', 0);
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['partnerships.read', 'partnerships.write']): Organization
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $org;
    }

    private function path(Organization $org, ?string $id = null): string
    {
        return '/api/v1/tenants/'.$org->id.'/sponsors'.($id === null ? '' : '/'.$id);
    }

    /** @return array<string, string> */
    private function exchange(): array
    {
        return ['channel' => 'meeting', 'summary' => 'Rendez-vous commercial', 'occurred_at' => '2026-01-01T12:00:00+00:00'];
    }
}
