<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\ProcessCompetitionRegistrationReminders;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCompetitionRegistrations;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CompetitionRegistrationControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesCompetitionRegistrations,ProvidesExternalCompetitions,ProvidesPeopleAccess;

    public function test_private_followup_preserves_roster_requirements_and_versioned_statuses(): void
    {
        $s = $this->registrationScenario();
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data', null);
        $first = $this->patchJson($s['path'], $s['input'])->assertOk()->assertJsonPath('data.payload.registration_status', 'preparing')->assertJsonPath('data.summary.pending', 1)->json('data');
        $this->assertSame($s['player']->pseudonym, $first['payload']['roster'][0]['label']);
        $this->getJson($s['path'].'/people?per_page=1')->assertOk()->assertJsonPath('meta.total', 2);
        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $first['version'], 'registration_status' => 'submitted', 'roster_status' => 'submitted', 'reason' => 'Le manager indique un dépôt effectué sur le site externe.'])->assertOk()->assertJsonPath('data.number', 2);
        $this->getJson($s['path'].'/history?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.payload.registration_status', 'preparing')->assertJsonPath('meta.total', 2);
        $this->assertDatabaseCount('competition_registration_revisions', 2);
        $this->assertStringNotContainsString('Suivi interne privé', DB::table('competition_registration_revisions')->value('payload'));
        $this->assertArrayNotHasKey('payload', CompetitionRegistrationRevision::query()->firstOrFail()->toArray());
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_stale_noop_and_archived_writes_leave_history_intact(): void
    {
        $s = $this->registrationScenario(true);
        $this->patchJson($s['path'], $s['input'])->assertConflict()->assertJsonPath('code', 'COMPETITION_REGISTRATION_STALE');
        $v = [...$s['input'], 'expected_version' => $s['revision']['version']];
        $this->patchJson($s['path'], $v)->assertUnprocessable()->assertJsonValidationErrors('registration');
        $this->patchJson($s['path'], [...$v, 'competition_version' => (string) Str::uuid(), 'notes' => 'Autre note'])->assertConflict();
        $this->patchJson($s['root'].'/'.$s['record']['id'], ['version' => $s['record']['version'], 'archive' => true, 'reason' => 'Participation terminée, conservation de son dossier.'])->assertOk();
        $this->patchJson($s['path'], [...$v, 'notes' => 'Modification après archive'])->assertConflict()->assertJsonPath('code', 'COMPETITION_REGISTRATION_ARCHIVED');
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.version', $s['revision']['version']);
        $this->assertDatabaseCount('competition_registration_revisions', 1);
    }

    #[Group('tenant-isolation')]
    public function test_reader_cannot_write_and_cross_tenant_roster_is_not_resolved(): void
    {
        $s = $this->registrationScenario();
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['path'])->assertOk();
        $this->patchJson($s['path'], $s['input'])->assertForbidden();
        $this->getJson($s['path'].'/people')->assertForbidden();
        $this->actingAsWithMfa($s['writer']);
        $foreign = Person::factory()->create();
        $this->patchJson($s['path'], [...$s['input'], 'roster' => [['person_id' => $foreign->id, 'role' => 'starter']]])->assertNotFound();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['writer'], ['sports.read', 'sports.competitions.read', 'sports.registrations.read', 'sports.registrations.write']);
        $path = '/api/v1/tenants/'.$other->id.'/external-competitions/'.$s['record']['id'].'/registration';
        foreach (['', '/history', '/people', '/reminders'] as $suffix) {
            $this->getJson($path.$suffix)->assertNotFound();
        }
        $this->patchJson($path, $s['input'])->assertNotFound();
        $this->assertDatabaseCount('competition_registration_revisions', 0);
    }

    public function test_links_to_private_versions_are_scoped_to_the_competition(): void
    {
        $s = $this->registrationScenario();
        $doc = $this->postJson($s['root'].'/'.$s['record']['id'].'/documents', $this->competitionDocument($s))->assertCreated()->json('data');
        $input = $s['input'];
        $input['items'][0]['document_version_id'] = $doc['version'];
        $input['items'][] = [...$input['items'][0], 'id' => (string) Str::uuid(), 'document_version_id' => strtoupper($doc['version'])];
        $first = $this->patchJson($s['path'], $input)->assertOk()->json('data');
        $input['expected_version'] = $first['version'];
        $input['items'][0]['document_version_id'] = (string) Str::uuid();
        $this->patchJson($s['path'], $input)->assertNotFound();
        $this->assertDatabaseCount('competition_registration_revisions', 1);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    #[TestWith(['read', 403])]
    public function test_revocation_blocks_warmed_registration_history_people_and_reminders_without_writes(string $revocation, int $status): void
    {
        $s = $this->registrationScenario(true);
        $this->generateReminders($s);
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['writer'], ['sports.read', 'sports.competitions.read', 'sports.registrations.read', 'sports.registrations.write']);
        foreach (['', '/history', '/people', '/reminders'] as $suffix) {
            $this->getJson($s['path'].$suffix)->assertOk();
        }
        $this->getJson($s['path'].'/reminders')->assertOk()->assertJsonCount(1, 'data');
        $before = CompetitionRegistrationRevision::query()->whereKey($s['revision']['version'])->firstOrFail()->getRawOriginal();
        $reminders = DB::table('competition_registration_reminders')->get()->map(fn (object $row): array => get_object_vars($row))->all();
        $auditCount = DB::table('organization_audit_events')->count();
        $assignment = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->firstOrFail();
        if ($revocation === 'membership') {
            OrganizationMembership::query()->whereKey($assignment->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } elseif ($revocation === 'role') {
            $assignment->forceFill(['status' => 'revoked'])->save();
        } else {
            $assignment->role->forceFill(['permissions' => ['sports.read', 'sports.competitions.read', 'sports.registrations.write']])->save();
        }

        foreach (['', '/history', '/people', '/reminders'] as $suffix) {
            $this->getJson($s['path'].$suffix)->assertStatus($status)->assertJsonMissingPath('data')->assertDontSee('Suivi interne privé');
        }
        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $s['revision']['version'], 'notes' => 'Modification interdite'])->assertStatus($status);
        $this->getJson('/api/v1/tenants/'.$other->id.'/context')->assertOk();

        $this->assertSame($before, CompetitionRegistrationRevision::query()->whereKey($s['revision']['version'])->firstOrFail()->getRawOriginal());
        $this->assertSame($reminders, DB::table('competition_registration_reminders')->get()->map(fn (object $row): array => get_object_vars($row))->all());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('competition_registration_revisions', 1);
        $this->assertDatabaseCount('competition_registration_reminders', 1);
    }

    #[Group('tenant-isolation')]
    public function test_removing_registration_write_preserves_reads_but_blocks_changes_people_choices_and_new_reminders(): void
    {
        $s = $this->registrationScenario(true);
        $this->generateReminders($s);
        $this->getJson($s['path'].'/history')->assertOk();
        $revision = CompetitionRegistrationRevision::query()->whereKey($s['revision']['version'])->firstOrFail();
        $payload = $revision->getRawOriginal('payload');
        $auditCount = DB::table('organization_audit_events')->count();
        OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->firstOrFail()
            ->role->forceFill(['permissions' => ['sports.read', 'sports.competitions.read', 'sports.registrations.read', 'sports.competitions.write']])->save();

        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.version', $s['revision']['version']);
        $this->getJson($s['path'].'/history')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($s['path'].'/reminders')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($s['path'].'/people')->assertForbidden();
        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $s['revision']['version'], 'notes' => 'Modification interdite'])->assertForbidden();
        $this->travelTo('2026-10-19T10:00:00Z');
        $this->generateReminders($s);

        $this->assertSame($payload, $revision->refresh()->getRawOriginal('payload'));
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('competition_registration_revisions', 1);
        $this->assertDatabaseCount('competition_registration_reminders', 1);
        $this->assertDatabaseMissing('competition_registration_reminders', ['stage' => 'day']);
        $this->getJson($s['path'].'/reminders')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Group('tenant-isolation')]
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_document_versions_from_another_competition_cannot_be_linked_or_downloaded_under_this_registration(bool $otherTenant): void
    {
        $s = $this->registrationScenario(true);
        if ($otherTenant) {
            $foreign = $this->competitionScenario();
            $this->grantPeopleAccess($foreign['org'], $s['writer'], ['sports.read', 'sports.competitions.read', 'sports.competitions.write', 'sports.registrations.read', 'sports.registrations.write']);
        } else {
            $foreign = $s;
            $foreign['record'] = $this->postJson($s['root'], [...$s['body'], 'reference' => 'SECOND_COMPETITION'])->assertCreated()->json('data');
        }
        $this->actingAsWithMfa($s['writer']);
        $root = $foreign['root'].'/'.$foreign['record']['id'].'/documents';
        $doc = $this->postJson($root, $this->competitionDocument($foreign))->assertCreated()->json('data');
        $download = $root.'/'.$doc['document_id'].'/versions/'.$doc['version'].'/download';
        $this->get($download)->assertOk()->assertSee('Règlement source', false);
        $before = CompetitionRegistrationRevision::query()->whereKey($s['revision']['version'])->firstOrFail()->getRawOriginal();
        $auditCount = DB::table('organization_audit_events')->count();
        $items = $s['input']['items'];
        $items[0]['document_version_id'] = $doc['version'];

        $this->patchJson($s['path'], [...$s['input'], 'expected_version' => $s['revision']['version'], 'items' => $items])->assertNotFound();
        $wrongRoot = $s['root'].'/'.$s['record']['id'].'/documents/'.$doc['document_id'];
        $this->getJson($wrongRoot.'/history')->assertNotFound();
        $this->getJson($wrongRoot.'/versions/'.$doc['version'].'/download')->assertNotFound();

        $this->assertSame($before, CompetitionRegistrationRevision::query()->whereKey($s['revision']['version'])->firstOrFail()->getRawOriginal());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('competition_registration_revisions', 1);
        $this->assertDatabaseCount('external_competition_documents', 1);
        $this->get($download)->assertOk()->assertSee('Règlement source', false);
    }

    /** @param array<string, mixed> $scenario */
    private function generateReminders(array $scenario): void
    {
        $run = (string) Str::uuid();
        app(ProcessCompetitionRegistrationReminders::class)->run(new TenantExecutionContext($scenario['org']->id, $run, 'UTC', 'fr'), $run);
    }

    /**
     * @return array<string,array{string,mixed}> */
    public static function invalid(): array
    {
        return [
            'unknown' => ['file', 'untrusted'], 'wildcard' => ['items.*.id', 'ignored'], 'unknown nested' => ['requirements', ['age' => null, 'residence' => null, 'rank' => null, 'contract_eligible' => true]],
            'bad state' => ['registration_status', 'automatically_accepted'], 'bank field' => ['bank_account', 'secret'], 'url' => ['source_url', 'javascript:alert(1)'],
            'private url' => ['source_url', 'https://127.0.0.1/private'], 'long notes' => ['notes', str_repeat('x', 2001)], 'short reason' => ['reason', 'a'],
            'roster shape' => ['roster', ['key' => ['person_id' => '00000000-0000-4000-8000-000000000000', 'role' => 'starter']]],
            'too many items' => ['items', array_fill(0, 51, [])], 'too many players' => ['roster', array_fill(0, 21, [])],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_or_unknown_values_are_refused_without_partial_writes(string $field, mixed $value): void
    {
        $s = $this->registrationScenario();
        $this->patchJson($s['path'], [...$s['input'], $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('competition_registration_revisions', 0);
    }

    public function test_item_dates_duplicates_and_submitted_empty_roster_are_refused(): void
    {
        $s = $this->registrationScenario();
        $input = $s['input'];
        $input['items'][0]['due_at'] = '2026-02-30T10:00:00+00:00';
        $this->patchJson($s['path'], $input)->assertUnprocessable()->assertJsonValidationErrors('items.0.due_at');
        $input = $s['input'];
        $input['items'][] = $input['items'][0];
        $this->patchJson($s['path'], $input)->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        $input = $s['input'];
        $input['roster'][] = $input['roster'][0];
        $this->patchJson($s['path'], $input)->assertUnprocessable()->assertJsonValidationErrors('roster.0.person_id');
        $this->patchJson($s['path'], [...$s['input'], 'roster_status' => 'approved', 'roster' => []])->assertUnprocessable()->assertJsonValidationErrors('roster');
    }

    public function test_audit_failure_rolls_back_and_audit_contains_no_private_payload(): void
    {
        $s = $this->registrationScenario();
        $this->mock(TenantAuditJournal::class, fn (MockInterface $mock) => $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable')));
        $this->patchJson($s['path'], $s['input'])->assertStatus(500);
        $this->assertDatabaseCount('competition_registration_revisions', 0);
    }

    public function test_audit_contains_identifiers_without_private_payload(): void
    {
        $s = $this->registrationScenario(true);
        $events = DB::table('organization_audit_events')->where('organization_id', $s['org']->id)->get();
        $serialized = json_encode($events, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('registration_updated', $serialized);
        $this->assertStringNotContainsString('Suivi interne privé', $serialized);
    }

    public function test_mfa_context_confirmation_and_session_authentication_are_enforced(): void
    {
        $s = $this->registrationScenario();
        $contextRevision = (string) Str::uuid();
        $this->withHeader('X-Tenant-Revision', $contextRevision)->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $s['org']->id, 'actor_user_id' => $s['writer']->id, 'revision' => $contextRevision, 'confirmed' => false]])->patchJson($s['path'], $s['input'])->assertConflict();
        $this->withSession([MfaSession::PROOF_KEY => null])->getJson($s['path'])->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/v1/tenants/'.Str::uuid().'/external-competitions/'.Str::uuid().'/registration')->assertUnauthorized();
    }

    public function test_changing_an_approved_roster_requires_reassessment_and_retains_original_labels(): void
    {
        $s = $this->registrationScenario();
        $approved = [...$s['input'], 'roster_status' => 'approved'];
        $revision = $this->patchJson($s['path'], $approved)->assertOk()->json('data');
        $next = [...$approved, 'expected_version' => $revision['version']];
        $next['roster'][0]['role'] = 'substitute';
        $this->patchJson($s['path'], $next)->assertUnprocessable()->assertJsonValidationErrors('roster_status');
        $next['roster_status'] = 'draft';
        $this->patchJson($s['path'], $next)->assertOk()->assertJsonPath('data.payload.roster_status', 'draft');
        $this->getJson($s['path'].'/history?page=2&per_page=1')->assertOk()->assertJsonPath('data.0.payload.roster_status', 'approved');
    }
}
