<?php

namespace Tests\Feature;

use App\Modules\Contracts\Application\Services\ContractService;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Contracts\AdministrativePeopleDirectory;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ContractServiceTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{list<string>, bool, bool, bool}> */
    public static function permissions(): array
    {
        return ['none' => [[], false, false, false], 'sports' => [['sports.read', 'sports.write'], false, false, false],
            'finance' => [['financial.read', 'financial.write'], false, false, false],
            'reader' => [['administrative.read'], true, false, false],
            'writer' => [['administrative.read', 'administrative.write'], true, true, false],
            'validator without writer' => [['administrative.read', 'administrative.validate'], true, false, false],
            'validator' => [['administrative.read', 'administrative.write', 'administrative.validate'], true, true, true]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('permissions')]
    public function test_policies_separate_read_prepare_and_validation_rights(array $permissions, bool $read, bool $write, bool $validate): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $context = $this->personContext($org, $user);
        $gate = Gate::forUser($user);
        $this->assertSame($read, $gate->allows('viewAny', [ContractRecord::class, $context]));
        $this->assertSame($write, $gate->allows('manage', [ContractRecord::class, $context]));
        $this->assertSame($write, $gate->allows('transition', [ContractRecord::class, $context, 'in_review']));
        $this->assertSame($validate, $gate->allows('transition', [ContractRecord::class, $context, 'approved']));
        $this->assertFalse(Gate::forUser(User::factory()->withMfa()->create())->allows('manage', [ContractRecord::class, $context]));
    }

    public function test_owner_and_technical_operator_have_no_implicit_contract_access(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $owner = User::findOrFail($org->owner_user_id);
        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', [ContractRecord::class, $this->personContext($org, $owner)]));
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['administrative.read', 'administrative.write']);
        PlatformRole::factory()->create(['user_id' => $user->id]);
        $this->assertFalse(Gate::forUser($user)->allows('manage', [ContractRecord::class, $this->personContext($org, $user)]));
    }

    public function test_audit_failure_rolls_back_creation_children_and_initial_history(): void
    {
        [$org, $user, $person] = $this->scenario();
        $audit = $this->mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(ContractService::class)->save($this->personContext($org, $user), $this->payload($person));
            $this->fail('An audit failure must roll back the entire dossier.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            foreach (['contracts', 'contract_parties', 'contract_obligations', 'contract_transitions'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_audit_failure_rolls_back_a_transition_and_its_history(): void
    {
        [$org, $user, $person] = $this->scenario();
        $context = $this->personContext($org, $user);
        $service = app(ContractService::class);
        $contract = $service->save($context, $this->payload($person));
        $audit = $this->mock(TenantAuditJournal::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(ContractService::class)->transition($context, $contract->id, ['version' => $contract->version, 'status' => 'in_review']);
            $this->fail('Failed audit must not leave the new state.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'draft', 'version' => $contract->version]);
            $this->assertDatabaseCount('contract_transitions', 1);
        }
    }

    public function test_captured_context_is_invalid_after_membership_revocation(): void
    {
        [$org, $user, $person] = $this->scenario();
        $context = $this->personContext($org, $user);
        OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->expectException(AuthorizationException::class);
        app(ContractService::class)->save($context, $this->payload($person));
    }

    public function test_list_queries_remain_constant_and_do_not_load_all_contract_children(): void
    {
        [$org, $user, $person] = $this->scenario();
        $context = $this->personContext($org, $user);
        for ($index = 0; $index < 5; $index++) {
            app(ContractService::class)->save($context, [...$this->payload($person), 'reference' => 'dossier-'.$index]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $small = app(ContractService::class)->paginate($context, ['per_page' => 1]);
        foreach ($small->items() as $record) {
            $this->assertSame(1, $record->parties_count);
            $this->assertFalse($record->relationLoaded('parties'));
        }
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        $large = app(ContractService::class)->paginate($context, ['per_page' => 5]);
        foreach ($large->items() as $record) {
            $this->assertSame(1, $record->parties_count);
            $this->assertSame(1, $record->pending_obligations_count);
        }
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_administrative_directory_never_requires_sporting_access_or_discloses_contacts(): void
    {
        [$org, $user, $person] = $this->scenario();
        $context = $this->personContext($org, $user);
        $directory = app(AdministrativePeopleDirectory::class);
        $this->assertSame([['id' => $person->id, 'label' => 'Camille Martin']], $directory->paginate($context, 1, 10)->items());
        $labels = DB::transaction(fn () => $directory->labels($context, [$person->id]));
        $this->assertSame([$person->id => 'Camille Martin'], $labels);
    }

    public function test_direct_service_rejects_empty_patches_and_unknown_financial_input(): void
    {
        [$org, $user, $person] = $this->scenario();
        $context = $this->personContext($org, $user);
        $contract = app(ContractService::class)->save($context, $this->payload($person));
        try {
            app(ContractService::class)->save($context, ['version' => $contract->version, 'amount' => 100], $contract->id);
            $this->fail('Financial input must be refused before persistence.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('amount', $error->errors());
        }
        $this->expectException(ValidationException::class);
        app(ContractService::class)->save($context, ['version' => $contract->version], $contract->id);
    }

    /** @return array{Organization, User, Person} */
    private function scenario(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, ['administrative.read', 'administrative.write', 'administrative.validate']);

        return [$org, $user, Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $org->id])];
    }

    /** @return array<string, mixed> */
    private function payload(Person $person): array
    {
        return ['reference' => 'dossier', 'type' => 'player', 'title' => 'Confidentiel', 'responsible_person_id' => $person->id,
            'parties' => [['kind' => 'person', 'person_id' => $person->id, 'name' => 'Partie']], 'obligations' => [['title' => 'Restitution']]];
    }
}
