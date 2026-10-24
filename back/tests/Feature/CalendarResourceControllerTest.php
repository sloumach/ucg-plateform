<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarResourceControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess;

    public function test_resource_management_needs_dedicated_permission_beyond_calendar_writing(): void
    {
        $org = Organization::factory()->create();
        $this->member($org, ['calendar.read']);
        $this->getJson($this->path($org))->assertForbidden();
        $this->member($org, ['calendar.read', 'calendar.write']);
        $this->getJson($this->path($org))->assertOk();
        $this->postJson($this->path($org), $this->input())->assertForbidden();
        $this->assertDatabaseCount('calendar_resources', 0);
    }

    public function test_resource_creation_encryption_pagination_unique_key_and_terminal_archive(): void
    {
        $org = Organization::factory()->create();
        $this->member($org, ['calendar.read', 'calendar.write', 'calendar.resources.manage']);
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->assertJsonPath('data.label', 'SECRET_ROOM')
            ->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.organization_id')->json('data');
        $this->assertNotSame('SECRET_ROOM', DB::table('calendar_resources')->value('label'));
        $this->postJson($this->path($org), $this->input())->assertConflict()->assertJsonPath('code', 'CALENDAR_RESOURCE_KEY_TAKEN');
        CalendarResource::factory()->create(['organization_id' => $org->id]);
        CalendarResource::factory()->create();
        $this->getJson($this->path($org).'?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $path = $this->path($org).'/'.$data['id'];
        $this->patchJson($path, ['version' => (string) Str::uuid(), 'label' => 'No'])->assertConflict();
        $updated = $this->patchJson($path, ['version' => $data['version'], 'label' => 'Autre salle'])->assertOk()->json('data');
        $archived = $this->patchJson($path, ['version' => $updated['version'], 'archive' => true])->assertOk()->assertJsonPath('data.status', 'archived')->json('data');
        $this->patchJson($path, ['version' => $archived['version'], 'label' => 'No'])->assertConflict();
        $this->getJson($this->path($org).'?archived=1')->assertJsonPath('meta.total', 1);
        $this->deleteJson($path)->assertStatus(405);
        $this->assertDatabaseCount('organization_audit_events', 3);
    }

    #[Group('tenant-isolation')]
    public function test_resource_mutations_reject_foreign_identity_and_closed_payloads(): void
    {
        $org = Organization::factory()->create();
        $this->member($org, ['calendar.read', 'calendar.write', 'calendar.resources.manage']);
        $foreign = CalendarResource::factory()->create();
        $this->patchJson($this->path($org).'/'.$foreign->id, ['version' => $foreign->version, 'archive' => true])->assertNotFound();
        foreach (['organization_id', 'created_by', 'status', 'label.*'] as $field) {
            $this->postJson($this->path($org), [...$this->input(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($this->path($org), [...$this->input(), 'key' => 'UPPER CASE'])->assertUnprocessable();
        $this->postJson($this->path($org), [...$this->input(), 'kind' => 'invented'])->assertUnprocessable();
        $data = $this->postJson($this->path($org), $this->input())->assertCreated()->json('data');
        $this->patchJson($this->path($org).'/'.$data['id'], ['version' => $data['version'], 'key' => 'other'])->assertUnprocessable();
        $this->patchJson($this->path($org).'/'.$data['id'], ['version' => $data['version'], 'label' => 'Mix', 'archive' => true])->assertUnprocessable();
    }

    /** @param list<string> $permissions */
    private function member(Organization $org, array $permissions): void
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);
    }

    private function path(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/calendar/resources';
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return ['key' => 'salle-1', 'label' => 'SECRET_ROOM', 'kind' => 'room'];
    }
}
