<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class TenantDataVisibilityTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase;

    /** @return array<string, array{string, list<string>, bool, array<string, mixed>}> */
    public static function visibilityMatrix(): array
    {
        return [
            'member' => ['member', [], true, ['name' => 'Alice']],
            'owner has no implicit business grant' => ['owner', [], true, ['name' => 'Alice']],
            'administrator has no implicit business grant' => ['administrator', [], true, ['name' => 'Alice']],
            'coach' => ['member', ['sports.read'], false, ['name' => 'Alice', 'rank' => 'Gold']],
            'administrative reader' => ['member', ['administrative.read'], true, ['name' => 'Alice', 'address' => 'Paris']],
            'financial reader' => ['member', ['financial.read'], true, ['name' => 'Alice', 'salary' => 500]],
            'financial reader without MFA' => ['member', ['financial.read'], false, ['name' => 'Alice']],
            'write does not imply read' => ['member', ['financial.write'], true, ['name' => 'Alice']],
        ];
    }

    /** @param list<string> $permissions
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('visibilityMatrix')]
    public function test_only_current_read_grants_expose_classified_fields(string $baseRole, array $permissions, bool $mfa, array $expected): void
    {
        $organization = Organization::factory()->withMfaOwner()->create();
        $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id,
            'user_id' => $baseRole === 'owner' ? $organization->owner_user_id : User::factory(),
            'roles' => [$baseRole === 'administrator' ? 'administrator' : 'member']]);
        if ($mfa) {
            $this->actingAsWithMfa(User::findOrFail($membership->user_id));
        }
        if ($permissions !== []) {
            $this->grant($membership, $permissions);
        }
        $record = new TenantDataRecord($organization->id, ['name' => 'Alice', 'rank' => 'Gold', 'address' => 'Paris', 'salary' => 500,
            'unknown_private_field' => 'never expose']);

        $data = app(TenantDataVisibility::class)->project($this->context($membership), $record, $this->fields());

        $this->assertSame($expected, $data);
    }

    public function test_objects_and_collection_items_are_filtered_recursively_before_serialization(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $record = new TenantDataRecord($membership->organization_id, ['profile' => ['name' => 'Alice', 'salary' => 500, 'unknown' => 'secret'],
            'players' => [['name' => 'Bob', 'salary' => 600, 'unknown' => 'secret']], 'tags' => ['one', 'two'], 'optional' => null]);
        $fields = ['profile' => new FieldVisibility('organization.view', $this->fields()),
            'players' => new FieldVisibility('organization.view', $this->fields(), true),
            'tags' => new FieldVisibility('organization.view'), 'optional' => new FieldVisibility('organization.view')];

        $result = app(TenantDataVisibility::class)->project($this->context($membership), $record, $fields);

        $this->assertSame(['profile' => ['name' => 'Alice'], 'players' => [['name' => 'Bob']], 'tags' => ['one', 'two'], 'optional' => null], $result);
    }

    public function test_one_projection_service_rechecks_revoked_grants_between_deliveries(): void
    {
        $this->freezeTime();
        $membership = OrganizationMembership::factory()->withMfaAccount()->create();
        $assignment = $this->grant($membership, ['financial.read']);
        $context = $this->context($membership);
        $record = new TenantDataRecord($membership->organization_id, ['name' => 'Alice', 'salary' => 500]);
        $service = app(TenantDataVisibility::class);
        $this->assertSame(['name' => 'Alice', 'salary' => 500], $service->project($context, $record, $this->fields()));
        $assignment->forceFill(['status' => 'revoked'])->save();

        $this->assertSame(['name' => 'Alice'], $service->project($context, $record, $this->fields()));
    }

    public function test_projection_rechecks_mf_a_instead_of_context_permissions(): void
    {
        $membership = OrganizationMembership::factory()->withMfaAccount()->create();
        $this->grant($membership, ['financial.read']);
        $context = $this->context($membership);
        $record = new TenantDataRecord($membership->organization_id, ['salary' => 500]);
        $service = app(TenantDataVisibility::class);
        $this->assertSame(['salary' => 500], $service->project($context, $record, $this->fields()));
        User::findOrFail($membership->user_id)->forceFill(['mfa_confirmed_at' => null])->save();

        $this->assertSame([], $service->project($context, $record, $this->fields()));
    }

    /** @return array<string, array{string}> */
    public static function closedAccess(): array
    {
        return ['suspended tenant' => ['tenant'], 'revoked membership' => ['membership']];
    }

    #[DataProvider('closedAccess')]
    public function test_lost_tenant_access_refuses_even_public_fields(string $change): void
    {
        $membership = OrganizationMembership::factory()->create();
        $context = $this->context($membership);
        if ($change === 'tenant') {
            Organization::findOrFail($membership->organization_id)->forceFill(['status' => 'suspended'])->save();
        } else {
            $membership->forceFill(['status' => 'revoked'])->save();
        }

        $this->expectException(AuthorizationException::class);
        app(TenantDataVisibility::class)->project($context, new TenantDataRecord($membership->organization_id, ['name' => 'Alice']), $this->fields());
    }

    public function test_a_mixed_tenant_batch_is_refused_without_returning_foreign_data(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $records = [new TenantDataRecord($membership->organization_id, ['name' => 'Alice']),
            new TenantDataRecord((string) Str::uuid(), ['name' => 'Foreign'])];

        $this->expectException(ModelNotFoundException::class);
        app(TenantDataVisibility::class)->projectMany($this->context($membership), $records, $this->fields());
    }

    public function test_team_grant_does_not_expose_another_teams_fields(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $team = (string) Str::uuid();
        $other = (string) Str::uuid();
        $org = $membership->organization_id;
        $this->app->instance(AuthorizationScopeDirectory::class, new class($org, $team, $other) implements AuthorizationScopeDirectory
        {
            public function __construct(private string $org, private string $team, private string $other) {}

            public function exists(string $organizationId, string $type, string $id): bool
            {
                return $organizationId === $this->org && (($type === 'organization' && $id === $this->org)
                    || ($type === 'team' && in_array($id, [$this->team, $this->other], true)));
            }

            public function availableTypes(): array
            {
                return ['organization', 'team'];
            }
        });
        $this->grant($membership, ['sports.read'], $team);
        $records = [new TenantDataRecord($org, ['name' => 'Alice', 'rank' => 'Gold'], 'team', $team),
            new TenantDataRecord($org, ['name' => 'Bob', 'rank' => 'Silver'], 'team', $other)];

        $result = app(TenantDataVisibility::class)->projectMany($this->context($membership), $records, $this->fields());

        $this->assertSame([['name' => 'Alice', 'rank' => 'Gold'], ['name' => 'Bob']], $result);
    }

    public function test_unregistered_scope_refuses_even_unclassified_data(): void
    {
        $membership = OrganizationMembership::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(TenantDataVisibility::class)->project($this->context($membership),
            new TenantDataRecord($membership->organization_id, ['name' => 'Alice'], 'team', (string) Str::uuid()), $this->fields());
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidSchemas(): array
    {
        return ['unknown permission' => ['Alice', 'platform.admin'], 'wildcard' => ['Alice', '*'],
            'object without schema' => [['salary' => 500], 'organization.view'],
            'nested list without schema' => [[['salary' => 500]], 'organization.view']];
    }

    #[DataProvider('invalidSchemas')]
    public function test_invalid_output_schema_cannot_pass_structured_data_through(mixed $value, string $permission): void
    {
        $membership = OrganizationMembership::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        app(TenantDataVisibility::class)->project($this->context($membership),
            new TenantDataRecord($membership->organization_id, ['name' => $value]), ['name' => new FieldVisibility($permission)]);
    }

    public function test_batch_query_count_stays_constant_as_the_page_grows(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $context = $this->context($membership);
        $record = new TenantDataRecord($membership->organization_id, ['name' => 'Alice', 'rank' => 'Gold', 'salary' => 500]);
        $service = app(TenantDataVisibility::class);
        DB::enableQueryLog();
        $service->project($context, $record, $this->fields());
        $singleQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $result = $service->projectMany($context, array_fill(0, 100, $record), $this->fields());

        $this->assertCount(100, $result);
        $this->assertSame($singleQueries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /** @return array<string, array{mixed, bool}> */
    public static function invalidNestedData(): array
    {
        return ['scalar instead of object' => ['Alice', false], 'list instead of object' => [['Alice'], false],
            'object instead of collection' => [['name' => 'Alice'], true], 'scalar collection item' => [[12], true],
            'list collection item' => [[['Alice']], true]];
    }

    #[DataProvider('invalidNestedData')]
    public function test_nested_shape_mismatch_is_refused_instead_of_serialized(mixed $value, bool $collection): void
    {
        $membership = OrganizationMembership::factory()->create();
        $record = new TenantDataRecord($membership->organization_id, ['players' => $value]);

        $this->expectException(InvalidArgumentException::class);
        app(TenantDataVisibility::class)->project($this->context($membership), $record,
            ['players' => new FieldVisibility('organization.view', $this->fields(), $collection)]);
    }

    public function test_a_hidden_field_is_omitted_without_inspecting_its_private_payload(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $record = new TenantDataRecord($membership->organization_id, ['name' => 'Alice', 'salary' => ['private' => 'secret']]);

        $result = app(TenantDataVisibility::class)->project($this->context($membership), $record, $this->fields());

        $this->assertSame(['name' => 'Alice'], $result);
    }

    public function test_a_collection_without_an_item_schema_is_refused(): void
    {
        $membership = OrganizationMembership::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        app(TenantDataVisibility::class)->project($this->context($membership),
            new TenantDataRecord($membership->organization_id, ['players' => []]), ['players' => new FieldVisibility('organization.view', collection: true)]);
    }

    public function test_role_htt_p_response_exposes_only_projected_fields_and_keeps_pagination(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        OrganizationRole::factory()->count(2)->create(['organization_id' => $org->id]);
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));

        $response = $this->getJson('/api/v1/tenants/'.$org->id.'/roles?per_page=1');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1);
        $this->assertSame(['id', 'organization_id', 'key', 'name', 'permissions', 'active', 'privileged', 'version'], array_keys($response->json('data.0')));
        $response->assertJsonMissingPath('data.0.created_at')->assertJsonMissingPath('data.0.updated_at');
    }

    public function test_oversized_batch_must_be_chunked(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $record = new TenantDataRecord($membership->organization_id, ['name' => 'Alice']);

        $this->expectException(InvalidArgumentException::class);
        app(TenantDataVisibility::class)->projectMany($this->context($membership), array_fill(0, 201, $record), $this->fields());
    }

    /** @return array<string, FieldVisibility> */
    private function fields(): array
    {
        return ['name' => new FieldVisibility('organization.view'), 'rank' => new FieldVisibility('sports.read'),
            'address' => new FieldVisibility('administrative.read'), 'salary' => new FieldVisibility('financial.read')];
    }

    /** @param list<string> $permissions */
    private function grant(OrganizationMembership $membership, array $permissions, ?string $team = null): OrganizationRoleAssignment
    {
        $role = OrganizationRole::factory()->create(['organization_id' => $membership->organization_id, 'permissions' => $permissions,
            'privileged' => $permissions !== ['sports.read']]);

        return OrganizationRoleAssignment::factory()->create(['organization_id' => $membership->organization_id, 'membership_id' => $membership->id,
            'user_id' => $membership->user_id, 'role_id' => $role->id, 'scope_type' => $team === null ? 'organization' : 'team',
            'scope_id' => $team ?? $membership->organization_id]);
    }

    private function context(OrganizationMembership $membership): TenantContext
    {
        $org = Organization::findOrFail($membership->organization_id);

        return new TenantContext($org->id, $membership->user_id, $org->slug, 'UTC', 'fr', (string) Str::uuid(), ['administrator'], true,
            effectivePermissions: ['organization.view', 'sports.read', 'administrative.read', 'financial.read']);
    }
}
