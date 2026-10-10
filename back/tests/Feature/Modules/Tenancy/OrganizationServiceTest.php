<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_an_active_organization_with_settings_and_correlated_audit(): void
    {
        $owner = User::factory()->create();
        $this->freezeTime();
        $organization = app(OrganizationService::class)->create('ucg-test', $owner->id,
            new OrganizationDetailsData('UCG Test', 'Europe/Paris', 'fr', 'FR', 7, 'Y-m-d'), $this->audit($owner));
        $this->assertTrue($organization->acceptsBusinessOperations());
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'slug' => 'ucg-test', 'owner_user_id' => $owner->id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id, 'week_starts_on' => '7', 'date_format' => 'Y-m-d']);
        $this->assertDatabaseHas('organization_lifecycle_events', [
            'organization_id' => $organization->id, 'actor_user_id' => $owner->id, 'action' => 'created',
            'from_status' => null, 'to_status' => 'active', 'reason' => 'Test opérateur',
            'request_id' => 'a646bd57-c6df-47a1-8073-5aa4d89a7cbc',
        ]);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function transitions(): array
    {
        return [
            'active-suspended' => ['active', 'suspended', true],
            'active-closing' => ['active', 'closing', true],
            'suspended-active' => ['suspended', 'active', true],
            'suspended-closing' => ['suspended', 'closing', true],
            'closing-archived' => ['closing', 'archived', true],
            'active-active' => ['active', 'active', false],
            'active-archived' => ['active', 'archived', false],
            'suspended-suspended' => ['suspended', 'suspended', false],
            'suspended-archived' => ['suspended', 'archived', false],
            'closing-active' => ['closing', 'active', false],
            'closing-suspended' => ['closing', 'suspended', false],
            'closing-closing' => ['closing', 'closing', false],
            'archived-active' => ['archived', 'active', false],
            'archived-suspended' => ['archived', 'suspended', false],
            'archived-closing' => ['archived', 'closing', false],
            'archived-archived' => ['archived', 'archived', false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_applies_only_authorized_transitions_and_audits_successes(string $from, string $to, bool $allowed): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id, 'status' => $from]);
        try {
            app(OrganizationService::class)->transition($organization->id, OrganizationStatus::from($to), $this->audit($owner));
            $this->assertTrue($allowed, 'An unauthorized transition was accepted.');
        } catch (DomainConflictException $exception) {
            $this->assertFalse($allowed, 'An authorized transition was rejected.');
            $this->assertSame('ORGANIZATION_TRANSITION_DENIED', $exception->errorCode());
        }
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => $allowed ? $to : $from]);
        $this->assertDatabaseCount('organization_lifecycle_events', $allowed ? 1 : 0);
        if ($allowed) {
            $this->assertDatabaseHas('organization_lifecycle_events', ['organization_id' => $organization->id, 'from_status' => $from, 'to_status' => $to]);
        }
    }

    public function test_rolls_back_creation_when_audit_persistence_fails(): void
    {
        $owner = User::factory()->create();
        Schema::drop('organization_lifecycle_events');
        try {
            app(OrganizationService::class)->create('audit-failure', $owner->id,
                new OrganizationDetailsData('Audit failure', 'UTC', 'fr', 'FR'), $this->audit($owner));
            $this->fail('Creation must fail if its audit cannot be saved.');
        } catch (QueryException) {
            $this->assertDatabaseCount('organizations', 0);
            $this->assertDatabaseCount('organization_settings', 0);
        }
    }

    public function test_rejects_duplicate_slugs_without_partial_data(): void
    {
        $owner = User::factory()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id, 'slug' => 'duplicate']);
        try {
            app(OrganizationService::class)->create('duplicate', $owner->id,
                new OrganizationDetailsData('Duplicate', 'UTC', 'fr', 'FR'), $this->audit($owner));
            $this->fail('Duplicate slug must fail.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('ORGANIZATION_SLUG_TAKEN', $exception->errorCode());
            $this->assertDatabaseCount('organizations', 1);
            $this->assertDatabaseCount('organization_settings', 1);
            $this->assertDatabaseCount('organization_lifecycle_events', 0);
        }
    }

    public function test_rolls_back_status_change_when_audit_persistence_fails(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        Schema::drop('organization_lifecycle_events');

        try {
            app(OrganizationService::class)->transition($organization->id, OrganizationStatus::Suspended, $this->audit($owner));
            $this->fail('A status change must fail if its audit cannot be saved.');
        } catch (QueryException) {
            $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'active']);
        }
    }

    public function test_rejects_a_blank_audit_reason_without_changing_status(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        try {
            app(OrganizationService::class)->transition($organization->id, OrganizationStatus::Suspended,
                new OrganizationAuditData($owner->id, ' ', 'a646bd57-c6df-47a1-8073-5aa4d89a7cbc'));
            $this->fail('A reason is mandatory.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
            $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'active']);
            $this->assertDatabaseCount('organization_lifecycle_events', 0);
        }
    }

    private function audit(User $owner): OrganizationAuditData
    {
        return new OrganizationAuditData($owner->id, 'Test opérateur', 'a646bd57-c6df-47a1-8073-5aa4d89a7cbc');
    }
}
