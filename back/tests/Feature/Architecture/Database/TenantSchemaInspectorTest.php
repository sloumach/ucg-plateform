<?php

namespace Tests\Feature\Architecture\Database;

use App\Architecture\Database\TableOwnership;
use App\Architecture\Database\TenantSchemaInspector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class TenantSchemaInspectorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_current_schema_and_read_only_operator_check_pass(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $this->consoleCommand()
            ->expectsOutput('Le schéma multi-tenant respecte les contraintes vérifiées.')
            ->assertSuccessful();
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_access_events', 0);
    }

    public function test_unknown_tables_cannot_become_global_implicitly(): void
    {
        Schema::create('unreviewed_records', function (Blueprint $table): void {
            $table->id();
        });

        $this->assertFalse(TableOwnership::isGlobal('unreviewed_records'));
        $this->assertContains('unreviewed_records: organization_id NOT NULL requis.', app(TenantSchemaInspector::class)->violations());
        $this->consoleCommand()->assertFailed();
    }

    public function test_nullable_organization_and_missing_root_foreign_key_are_reported(): void
    {
        Schema::create('unsafe_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('organization_id')->nullable()->index();
        });

        $violations = app(TenantSchemaInspector::class)->violations();

        $this->assertContains('unsafe_records: organization_id NOT NULL requis.', $violations);
        $this->assertContains('unsafe_records: clé étrangère organization_id vers organizations.id requise.', $violations);
    }

    public function test_unscoped_business_uniqueness_and_missing_tenant_index_are_reported(): void
    {
        Schema::create('unsafe_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code')->unique('unsafe_code_unique');
        });

        $violations = app(TenantSchemaInspector::class)->violations();

        $this->assertContains('unsafe_keys: unicité unsafe_code_unique sans organization_id.', $violations);
        $this->assertContains('unsafe_keys: index commençant par organization_id requis.', $violations);
    }

    public function test_single_column_relation_to_a_tenant_object_is_reported(): void
    {
        Schema::create('unsafe_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->index('organization_id');
            $table->foreignUuid('membership_id')->constrained('organization_memberships');
        });

        $this->assertContains('unsafe_links: relation vers organization_memberships sans organization_id apparié.', app(TenantSchemaInspector::class)->violations());
    }

    public function test_composite_relation_to_a_tenant_object_and_local_uniqueness_pass(): void
    {
        Schema::create('safe_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('membership_id');
            $table->string('code');
            $table->unique(['organization_id', 'code']);
            $table->foreign(['organization_id', 'membership_id'])->references(['organization_id', 'id'])->on('organization_memberships');
        });

        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_missing_tenant_root_is_not_reported_as_a_valid_schema(): void
    {
        Schema::rename('organizations', 'missing_root_fixture');

        $this->assertContains('organizations: table racine requise.', app(TenantSchemaInspector::class)->violations());
        $this->consoleCommand()->assertFailed();
    }

    public function test_business_primary_keys_are_not_exempt_from_tenant_uniqueness(): void
    {
        Schema::create('unsafe_primary_keys', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->index('organization_id');
        });

        $violations = app(TenantSchemaInspector::class)->violations();

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('unsafe_primary_keys: unicité', $violations[0]);
    }

    private function consoleCommand(): PendingCommand
    {
        $command = $this->artisan('tenancy:check-schema');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    public function test_cross_schema_references_cannot_reuse_a_global_allowlist_name(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This schema boundary is PostgreSQL-specific.');
        }
        DB::statement('CREATE SCHEMA ten004_external_fixture');
        Schema::create('ten004_external_fixture.users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('cross_schema_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->index('organization_id');
            $table->foreignId('actor_id')->constrained('ten004_external_fixture.users');
        });

        $this->assertContains('cross_schema_links: relation hors du schéma partagé courant interdite.', app(TenantSchemaInspector::class)->violations());
    }
}
