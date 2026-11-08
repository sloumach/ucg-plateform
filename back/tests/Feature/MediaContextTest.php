<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Application\Services\MediaService;
use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MediaContextTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    private const array EDITOR = ['media.read', 'media.write', 'media.publish', 'calendar.read', 'sports.read'];

    /** @return array<string, array{string}> */
    public static function sources(): array
    {
        return ['event' => ['event'], 'match' => ['match']];
    }

    #[DataProvider('sources')]
    public function test_existing_sources_can_be_selected_bound_filtered_and_edited(string $type): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, $type);
        $this->getJson($this->root($org).'/contexts?type='.$type.'&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $source->id)->assertJsonPath('data.0.version', $source->version);
        $data = $this->postJson($this->root($org), $this->input($org, $source, $type))->assertCreated()
            ->assertJsonPath('data.context_type', $type)->assertJsonPath('data.context_label', $source->title)->json('data');
        $this->getJson($this->root($org).'?context_type='.$type.'&context_id='.$source->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson($this->root($org).'/'.$data['id'], ['version' => $data['version'], 'context_version' => $source->version, 'title' => 'Renommé'])
            ->assertOk()->assertJsonPath('data.title', 'Renommé');
        $this->assertDatabaseCount('media_contexts', 1);
        $this->assertDatabaseHas('media_contexts', ['organization_id' => $org->id, 'media_id' => $data['id'], $type === 'event' ? 'activity_id' : 'match_id' => $source->id]);
    }

    #[DataProvider('sources')]
    #[Group('tenant-isolation')]
    public function test_foreign_sources_are_refused_and_revoked_source_rights_hide_media_even_for_editors(string $type): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, $type);
        $foreignOrg = Organization::factory()->create();
        $foreign = $this->source($foreignOrg, $user, $type);
        $this->postJson($this->root($org), $this->input($org, $foreign, $type))->assertNotFound();
        $data = $this->postJson($this->root($org), $this->input($org, $source, $type))->assertCreated()->json('data');
        $grant = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail();
        $grant->role->forceFill(['permissions' => ['media.read', 'media.write', 'media.publish']])->save();
        $this->getJson($this->root($org))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($org).'/'.$data['id'])->assertNotFound();
        $this->getJson($this->root($org).'/contexts?type='.$type)->assertForbidden();
        $this->patchJson($this->root($org).'/'.$data['id'], ['version' => $data['version'], 'archive' => true])->assertNotFound();
        $this->assertDatabaseCount('media_items', 1);
    }

    #[DataProvider('sources')]
    public function test_source_version_and_closed_references_are_required_before_writing(string $type): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, $type);
        $input = $this->input($org, $source, $type);
        $this->postJson($this->root($org), [...$input, 'context_version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'MEDIA_CONTEXT_VERSION_STALE');
        unset($input['context_version']);
        $this->postJson($this->root($org), $input)->assertUnprocessable()->assertJsonValidationErrors('context_version');
        $this->postJson($this->root($org), [...$input, 'context_type' => 'task'])->assertUnprocessable()->assertJsonValidationErrors('context_type');
        $this->assertDatabaseCount('media_items', 0);
        $this->assertDatabaseCount('media_contexts', 0);
    }

    #[DataProvider('sources')]
    public function test_a_binding_cannot_be_changed_or_detached_but_legacy_media_can_be_bound_once(string $type): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, $type);
        $other = $this->source($org, $user, $type);
        $input = $this->input($org, $source, $type);
        $legacy = $this->postJson($this->root($org), array_diff_key($input, array_flip(['context_type', 'context_id', 'context_version'])))->assertCreated()->json('data');
        $data = $this->patchJson($this->root($org).'/'.$legacy['id'], ['version' => $legacy['version'], 'context_type' => $type, 'context_id' => $source->id, 'context_version' => $source->version])
            ->assertOk()->json('data');
        $this->patchJson($this->root($org).'/'.$data['id'], ['version' => $data['version'], 'context_type' => $type, 'context_id' => $other->id, 'context_version' => $other->version])
            ->assertUnprocessable()->assertJsonValidationErrors('context_id');
        $this->patchJson($this->root($org).'/'.$data['id'], ['version' => $data['version'], 'context_id' => null])->assertUnprocessable();
        $this->assertDatabaseCount('media_contexts', 1);
    }

    #[DataProvider('sources')]
    public function test_private_source_cannot_be_widened_and_scrim_is_detected_without_trusting_the_payload(string $type): void
    {
        $this->freezeTime();
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, $type, true);
        $input = [...$this->input($org, $source, $type), 'is_scrim' => false];
        $data = $this->postJson($this->root($org), $input)->assertCreated()->assertJsonPath('data.is_scrim', true)->assertJsonPath('data.visibility', 'private')->json('data');
        $this->assertDatabaseHas('media_items', ['id' => $data['id'], 'is_scrim' => true]);
        $this->postJson($this->root($org), [...$input, 'visibility' => 'organization', 'confirm_sharing' => true])->assertUnprocessable()->assertJsonValidationErrors('publish_at');
        $this->postJson($this->root($org), [...$input, 'visibility' => 'public', 'publish_at' => now()->addHour()->toIso8601String(), 'confirm_sharing' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('visibility');
        $shared = $this->postJson($this->root($org), [...$input, 'visibility' => 'organization', 'publish_at' => now()->addHour()->toIso8601String(), 'confirm_sharing' => true])->assertCreated()->json('data');
        $this->member($org, ['media.read', 'calendar.read', 'sports.read']);
        $this->getJson($this->root($org).'/'.$shared['id'])->assertNotFound();
        $this->travel(2)->hours();
        $this->getJson($this->root($org).'/'.$shared['id'])->assertOk()->assertJsonPath('data.effective_visibility', 'organization');
        $source->forceFill(['visibility' => 'private', 'version' => (string) Str::uuid(), 'sharing_authorized_by' => null, 'sharing_authorized_at' => null])->save();
        $this->getJson($this->root($org).'/'.$shared['id'])->assertNotFound();
        $this->getJson($this->root($org))->assertJsonPath('meta.total', 0);
        $this->actingAsWithMfa($user);
        $this->getJson($this->root($org).'/'.$shared['id'])->assertOk()->assertJsonPath('data.effective_visibility', 'private');
        $this->postJson($this->root($org), [...$input, 'context_version' => $source->version, 'visibility' => 'organization', 'publish_at' => now()->addHour()->toIso8601String(), 'confirm_sharing' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('visibility');
    }

    public function test_new_scrim_classification_hides_previously_shared_media_until_reauthorized(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, 'event');
        $data = $this->postJson($this->root($org), [...$this->input($org, $source, 'event'), 'visibility' => 'organization', 'confirm_sharing' => true])->assertCreated()->json('data');
        $source->forceFill(['kind' => 'scrim', 'version' => (string) Str::uuid()])->save();
        $this->member($org, ['media.read', 'calendar.read']);
        $this->getJson($this->root($org))->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($org).'/'.$data['id'])->assertNotFound();
    }

    public function test_audit_failure_rolls_back_the_media_and_its_context(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, 'event');
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->root($org), $this->input($org, $source, 'event'))->assertStatus(500);
        $this->assertDatabaseCount('media_items', 0);
        $this->assertDatabaseCount('media_contexts', 0);
    }

    public function test_privacy_restriction_between_selection_and_metadata_never_returns_the_url_to_a_reader(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, 'event');
        $item = $this->postJson($this->root($org), [...$this->input($org, $source, 'event'), 'visibility' => 'organization', 'confirm_sharing' => true])
            ->assertCreated()->json('data');
        OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->firstOrFail()
            ->role->forceFill(['permissions' => ['media.read', 'calendar.read']])->save();
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        if ($dispatcher === null) {
            throw new \LogicException('The query dispatcher is required to reproduce this race.');
        }
        $connection->setEventDispatcher(clone $dispatcher);
        $changed = false;
        $connection->listen(function (QueryExecuted $query) use ($source, &$changed): void {
            if (! $changed && str_starts_with($query->sql, 'select * from "media_items"')) {
                $changed = true;
                $source->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'version' => (string) Str::uuid()])->save();
            }
        });
        try {
            $this->getJson($this->root($org).'/'.$item['id'])->assertNotFound();
            $this->assertTrue($changed);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
    }

    public function test_context_migration_round_trip_restores_tenant_foreign_keys_and_immutable_history(): void
    {
        $sponsorshipContexts = require database_path('migrations/2026_11_08_080532_create_sponsorship_exposure_contexts_table.php');
        $sponsorshipContexts->down();
        $this->assertDatabaseCount('media_contexts', 0);
        $migration = require database_path('migrations/2026_11_07_171421_create_media_contexts_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('media_contexts'));
        $migration->up();
        $sponsorshipContexts->up();
        app(PortabilityRepository::class)->assertCatalog();
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, 'event');
        $foreign = CalendarActivity::factory()->create();
        $item = MediaItem::factory()->create(['organization_id' => $org->id, 'team_id' => Team::factory()->create(['organization_id' => $org->id])->id]);
        $records = app(MediaRepository::class);
        $this->reject(fn () => $records->bindContext($item, 'activity_id', $foreign->id));
        $records->bindContext($item, 'activity_id', $source->id);
        $unbound = MediaItem::factory()->create(['team_id' => $item->team_id]);
        $this->reject(fn () => DB::table('media_contexts')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org->id, 'media_id' => $unbound->id, 'created_at' => now()]));
        $this->reject(fn () => DB::table('media_contexts')->where('media_id', $item->id)->update(['activity_id' => $foreign->id]));
        $this->reject(fn () => DB::table('media_contexts')->where('media_id', $item->id)->delete());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_metadata_queries_do_not_grow_with_the_number_of_media_on_a_page(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, $user, 'event');
        foreach (range(1, 5) as $index) {
            $this->postJson($this->root($org), $this->input($org, $source, 'event'))->assertCreated();
        }
        $service = app(MediaService::class);
        $context = $this->personContext($org, $user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $service->paginate($context, ['per_page' => 1]);
            $small = count(DB::getQueryLog());
            DB::flushQueryLog();
            $service->paginate($context, ['per_page' => 10]);
            $this->assertSame($small, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_preservation_hold_blocks_new_bindings_in_sql(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $source = CalendarActivity::factory()->create(['organization_id' => $org->id]);
        $item = MediaItem::factory()->create(['team_id' => Team::factory()->create(['organization_id' => $org->id])->id]);
        config(['retention.control_enabled' => true, 'retention.control_operators' => [$org->owner_user_id => [$org->id]]]);
        app(RetentionControlService::class)->run($org->id,
            new OrganizationAuditData($org->owner_user_id, 'Fixture media preservation', (string) Str::uuid7()),
            'hold', 0, 'media-hold', $org->id);
        try {
            DB::transaction(fn () => app(MediaRepository::class)->bindContext($item, 'activity_id', $source->id));
            $this->fail('Preservation must prevent a new media binding.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retention preservation blocks business writes', $error->getMessage());
        }
        $this->assertDatabaseCount('media_contexts', 0);
    }

    /** @return array{Organization, User} */
    private function editor(): array
    {
        $org = Organization::factory()->create();

        return [$org, $this->member($org, self::EDITOR)];
    }

    /** @param list<string> $permissions */
    private function member(Organization $org, array $permissions): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $user;
    }

    private function source(Organization $org, User $user, string $type, bool $scrim = false): CalendarActivity|MatchRecord
    {
        $shared = ['organization_id' => $org->id, 'created_by' => $user->id, 'visibility' => 'organization',
            'sharing_authorized_by' => $user->id, 'sharing_authorized_at' => now()];

        return $type === 'event' ? CalendarActivity::factory()->create([...$shared, 'kind' => $scrim ? 'scrim' : 'meeting'])
            : MatchRecord::factory()->create([...$shared, 'kind' => $scrim ? 'scrim' : 'official']);
    }

    private function root(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/media';
    }

    /** @return array<string, mixed> */
    private function input(Organization $org, CalendarActivity|MatchRecord $source, string $type): array
    {
        return ['title' => 'Média lié', 'team_id' => Team::factory()->create(['organization_id' => $org->id])->id,
            'kind' => 'vod', 'provider' => 'youtube', 'url' => 'https://youtu.be/abcdefghijk',
            'context_type' => $type, 'context_id' => $source->id, 'context_version' => $source->version];
    }

    /** @param \Closure(): mixed $operation */
    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Invalid media context must be rejected.');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0] ?? (string) $error->getCode(), ['23000', '23503', 'P0001']);
        }
    }
}
