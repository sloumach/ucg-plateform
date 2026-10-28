<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class RetentionBusinessFreezeTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @return array<string, array{class-string<Person>|class-string<Team>|class-string<ContractRecord>|class-string<CalendarActivity>, string}> */
    public static function businessRecords(): array
    {
        return ['person' => [Person::class, 'pseudonym'], 'team' => [Team::class, 'name'],
            'contract' => [ContractRecord::class, 'title'], 'calendar' => [CalendarActivity::class, 'title']];
    }

    /** @param class-string<Person>|class-string<Team>|class-string<ContractRecord>|class-string<CalendarActivity> $model */
    #[DataProvider('businessRecords')]
    public function test_direct_sql_updates_cannot_bypass_a_hold(string $model, string $column): void
    {
        $organization = $this->operator();
        $attributes = ['organization_id' => $organization->id];
        if ($model === ContractRecord::class) {
            $attributes['responsible_person_id'] = Person::factory()->create(['organization_id' => $organization->id])->id;
        }
        $record = $model::factory()->create($attributes);
        $table = $record->getTable();
        $original = DB::table($table)->where('id', $record->getKey())->first();
        $this->change($organization, 'hold', 0, 'case-A');

        try {
            DB::transaction(fn () => DB::table($table)->where('id', $record->getKey())->update([$column => 'Unexpected change']));
            $this->fail('Business writes must be rejected independently of Eloquent and HTTP.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Retention preservation blocks business writes', $exception->getMessage());
            $this->assertEquals($original, DB::table($table)->where('id', $record->getKey())->first());
        }
        $this->change($organization, 'release-hold', 1, 'case-A');
        $this->assertSame(1, DB::table($table)->where('id', $record->getKey())->update([$column => 'Explicitly resumed']));
    }

    #[Group('tenant-isolation')]
    public function test_insert_delete_and_another_tenant_are_correctly_scoped(): void
    {
        $organization = $this->operator();
        $other = Organization::factory()->create();
        $this->change($organization, 'hold', 0, 'case-A');
        foreach ([fn () => Person::factory()->create(['organization_id' => $organization->id]),
            fn () => DB::table('organization_settings')->where('organization_id', $organization->id)->delete()] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('A hold must reject creation and removal of business records.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Retention preservation blocks business writes', $exception->getMessage());
            }
        }
        $person = Person::factory()->create(['organization_id' => $other->id]);
        $this->assertModelExists($person);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id]);
        $this->assertDatabaseCount('organization_retention_events', 1);
        $owner = User::findOrFail($organization->owner_user_id);
        $owner->forceFill(['name' => 'Global account remains independent'])->save();
        $this->assertSame('Global account remains independent', $owner->fresh()?->name);
    }

    public function test_http_reports_the_freeze_and_reading_remains_available(): void
    {
        $organization = $this->operator();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        $person = Person::factory()->create(['organization_id' => $organization->id]);
        $this->actingAsWithMfa($user);
        $this->change($organization, 'hold', 0, 'case-A');
        $path = '/api/v1/tenants/'.$organization->id.'/people/'.$person->id;

        $this->patchJson($path, ['version' => $person->version, 'pseudonym' => 'Rejected'])->assertConflict()
            ->assertJsonPath('code', 'RETENTION_BUSINESS_FROZEN');
        $this->getJson($path)->assertOk()->assertJsonPath('data.id', $person->id);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'pseudonym' => $person->pseudonym, 'version' => $person->version]);
    }

    public function test_storage_creation_is_rejected_before_any_file_or_reservation_is_written(): void
    {
        Storage::fake('local');
        config(['filesystems.artifacts' => 'local']);
        $organization = $this->operator();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid7());
        $this->change($organization, 'hold', 0, 'case-A');
        try {
            app(TenantArtifacts::class)->store($context, 'refused.txt', 'refused');
            $this->fail('A frozen tenant must not create new storage content.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('RETENTION_BUSINESS_FROZEN', $exception->errorCode());
            $this->assertSame([], Storage::disk('local')->allFiles());
            $this->assertDatabaseCount('tenant_artifacts', 0);
        }
    }

    public function test_root_revision_cannot_be_forged_and_each_decision_advances_it(): void
    {
        $organization = $this->operator();
        $this->change($organization, 'hold', 0, 'case-A');
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'retention_version' => 1]);
        try {
            DB::transaction(fn () => DB::table('organizations')->where('id', $organization->id)->update(['retention_version' => 100]));
            $this->fail('A root retention revision must match its immutable decisions.');
        } catch (QueryException) {
            $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'retention_version' => 1]);
        }
        $this->change($organization, 'release-hold', 1, 'case-A');
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'retention_version' => 2]);
    }

    public function test_every_catalogued_table_has_its_expected_sql_freeze_guard(): void
    {
        $this->operator();
        foreach (RetentionCatalog::tables() as $table) {
            if (in_array($table, RetentionCatalog::ERASURE_CONTROL_TABLES, true)) {
                continue;
            }
            if (DB::getDriverName() === 'pgsql') {
                $this->assertSame(1, (int) DB::table('information_schema.triggers')->where('event_object_table', $table)
                    ->where('trigger_name', 'ucg_retention_business_guard')->where('event_manipulation', 'UPDATE')->count(), $table);
            } elseif ($table !== 'tenant_portability_operations') {
                $this->assertTrue(DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'ucg_retention_'.$table.'_UPDATE')->exists(), $table);
            }
        }
    }

    private function operator(): Organization
    {
        $this->freezeTime();
        $organization = Organization::factory()->withMfaOwner()->create();
        config(['retention.control_enabled' => true, 'retention.control_operators' => [$organization->owner_user_id => [$organization->id]]]);

        return $organization;
    }

    private function change(Organization $organization, string $action, int $version, string $reference): void
    {
        app(RetentionControlService::class)->run($organization->id, new OrganizationAuditData($organization->owner_user_id,
            'Fixture business preservation', (string) Str::uuid7()), $action, $version, $reference, $organization->id);
    }
}
