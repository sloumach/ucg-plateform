<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetentionFreezeConcurrencyTest extends TestCase
{
    public function test_a_stale_repeatable_read_writer_cannot_miss_a_committed_hold(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two real PostgreSQL connections are required.');
        }
        $original = DB::getDefaultConnection();
        $settings = config('database.connections.'.$original);
        $admin = DB::connection()->getPdo();
        $name = 'ucg_retention_race_'.bin2hex(random_bytes(6));
        try {
            $admin->exec('CREATE DATABASE "'.$name.'"');
            config(['database.connections.freeze_writer' => [...$settings, 'url' => null, 'database' => $name],
                'database.connections.freeze_operator' => [...$settings, 'url' => null, 'database' => $name]]);
            DB::setDefaultConnection('freeze_writer');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $org = Organization::factory()->withMfaOwner()->create();
            $person = Person::factory()->create(['organization_id' => $org->id]);
            $writer = DB::connection('freeze_writer');
            $operator = DB::connection('freeze_operator');
            $writer->beginTransaction();
            $writer->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertSame(0, (int) $writer->table('organizations')->where('id', $org->id)->value('retention_version'));
            $operator->transaction(function () use ($operator, $org): void {
                $operator->table('organizations')->where('id', $org->id)->lockForUpdate()->firstOrFail();
                $operator->table('organization_retention_events')->insert([
                    'id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'actor_user_id' => $org->owner_user_id,
                    'version' => 1, 'action' => 'hold', 'reference' => 'concurrent-preservation',
                    'state' => json_encode(['version' => 1, 'holds' => ['concurrent-preservation'], 'restricted_archive' => false, 'quarantine' => null], JSON_THROW_ON_ERROR),
                    'reason' => 'Dedicated concurrency fixture', 'request_id' => (string) Str::uuid7(), 'occurred_at' => now(),
                ]);
                $operator->table('organizations')->where('id', $org->id)->update(['retention_version' => 1]);
            });
            try {
                $writer->table('people')->where('id', $person->id)->update(['pseudonym' => 'Must be rejected']);
                $this->fail('An old snapshot must serialize or abort before changing preserved data.');
            } catch (QueryException $exception) {
                $this->assertSame('40001', $exception->errorInfo[0] ?? null);
            } finally {
                $writer->rollBack();
            }
            $this->assertDatabaseHas('people', ['id' => $person->id, 'pseudonym' => $person->pseudonym]);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('freeze_writer');
            DB::purge('freeze_operator');
            $admin->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        }
    }
}
