<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Contracts\MatchNotebookRepository;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatches;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchNotebookSchemaTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMatches,ProvidesPeopleAccess;

    public function test_empty_roundtrip_and_complete_tenant_inventory(): void
    {
        $migration = require database_path('migrations/2026_10_18_155140_create_match_notebook_revisions.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('match_notebook_revisions'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_database_retains_versions_and_refuses_cross_tenant_and_audience_changes(): void
    {
        [$org,$writer,,$person,$other,$game] = $this->matchScenario();
        $record = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');
        $repo = app(MatchNotebookRepository::class);
        $id = (string) Str::uuid();
        $attrs = ['id' => $id, 'entry_id' => $id, 'number' => 1, 'organization_id' => $org->id, 'match_id' => $record['id'], 'kind' => 'note', 'audience' => 'strategy',
            'title' => 'Stratégie protégée', 'body' => 'Contenu interne secret', 'actor_user_id' => $writer->id];
        $repo->append($attrs);
        $this->reject(fn () => DB::table('match_notebook_revisions')->update(['body' => 'overwrite']));
        $this->reject(fn () => DB::table('match_notebook_revisions')->delete());
        $foreign = MatchRecord::factory()->create();
        $this->reject(fn () => $repo->append([...$attrs, 'id' => (string) Str::uuid(), 'match_id' => $foreign->id]));
        foreach (['audience' => 'shared', 'kind' => 'vod', 'entry_id' => (string) Str::uuid(), 'number' => 4] as $field => $value) {
            $this->reject(fn () => $repo->append([...$attrs, 'id' => (string) Str::uuid(), 'number' => 2, $field => $value]));
        }
        $repo->append([...$attrs, 'id' => (string) Str::uuid(), 'number' => 2, 'body' => 'Stratégie révisée']);
        $this->assertDatabaseCount('match_notebook_revisions', 2);
        $rows = array_values(array_filter(iterator_to_array(app(PortabilityRepository::class)->rows($org->id, 100)), fn ($r) => $r['table'] === 'match_notebook_revisions'));
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('secret', DB::table('match_notebook_revisions')->value('body'));
        $this->assertNotSame('Stratégie protégée', $rows[0]['data']['title']);
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_18_155140_create_match_notebook_revisions.php'))->down();
    }

    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail('SQL must refuse the invalid notebook mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
