<?php

namespace Tests\Feature;

use App\Modules\Competition\Infrastructure\Broadcasting\MatchScoreChanged;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchScores;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ReverbDeliveryTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchScores, ProvidesPeopleAccess;

    #[Group('reverb-integration')]
    public function test_real_private_delivery_reaches_only_the_authorized_tenant_socket(): void
    {
        if (getenv('UCG_TEST_REVERB') !== '1') {
            $this->markTestSkipped('Opt-in real Reverb transport: UCG_TEST_REVERB=1 and Node 24 required.');
        }
        $first = $this->scoreScenario();
        $second = $this->scoreScenario();
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if ($listener === false) {
            throw new \RuntimeException('Cannot reserve isolated Reverb port: '.$message);
        }
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $this->assertIsString($address);
        $port = substr($address, strrpos($address, ':') + 1);
        $key = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $appId = 'qa002-'.bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg-reverb-'.bin2hex(random_bytes(16));
        mkdir($directory, 0700);
        $server = new Process([PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', '--port='.$port, '--no-interaction'], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'REVERB_APP_ID' => $appId, 'REVERB_APP_KEY' => $key, 'REVERB_APP_SECRET' => $secret,
            'REVERB_HOST' => '127.0.0.1', 'REVERB_PORT' => $port, 'REVERB_SCHEME' => 'http',
            'REVERB_SCALING_ENABLED' => 'false', 'REVERB_ALLOWED_ORIGINS' => '*',
            'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'stderr',
        ]);
        $client = new Process([getenv('UCG_TEST_NODE') ?: 'node', base_path('tests/Fixtures/reverb-clients.mjs'), $directory, $port, $key], base_path());
        $server->setTimeout(30);
        $client->setTimeout(25);
        try {
            $server->start();
            $client->start();
            $sockets = $this->exchange($directory.'/sockets.json', $client);
            config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => $key, 'secret' => $secret, 'app_id' => $appId,
                'options' => ['host' => '127.0.0.1', 'port' => (int) $port, 'scheme' => 'http', 'useTLS' => false],
            ]]);
            app(BroadcastManager::class)->purge('reverb');
            require base_path('routes/channels.php');
            $subscriptions = [];
            foreach ([$first, $second] as $index => $scenario) {
                $channel = 'private-tenants.'.$scenario['org']->id.'.matches.'.$scenario['record']['id'];
                $this->actingAsWithMfa($scenario['reader']);
                $auth = $this->postJson($scenario['root'].'/realtime/auth', ['socket_id' => $sockets[$index], 'channel_name' => $channel])->assertOk()->json('auth');
                $subscriptions[] = ['channel' => $channel, 'auth' => $auth];
            }
            $this->actingAsWithMfa($second['reader']);
            $this->postJson($first['root'].'/realtime/auth', ['socket_id' => $sockets[2], 'channel_name' => $subscriptions[0]['channel']])->assertNotFound();
            $subscriptions[] = ['channel' => $subscriptions[0]['channel'], 'auth' => $key.':invalid-signature'];
            file_put_contents($directory.'/auth.json.pending', json_encode($subscriptions, JSON_THROW_ON_ERROR));
            rename($directory.'/auth.json.pending', $directory.'/auth.json');
            $this->assertSame(['ready' => true, 'rejected' => true], $this->exchange($directory.'/subscribed.json', $client));

            $manager = app(BroadcastManager::class);
            foreach ([$first, $second] as $scenario) {
                $event = new MatchScoreChanged($scenario['org']->id, $scenario['record']['id'], (string) Str::uuid(), '2026-10-18T10:00:00Z');
                $manager->connection('reverb')->broadcast($event->broadcastOn(), $event->broadcastAs(), $event->broadcastWith());
            }
            $received = $this->exchange($directory.'/received.json', $client);
            $this->assertCount(1, $received[0]);
            $this->assertCount(1, $received[1]);
            $this->assertSame([], $received[2]);
            foreach ([$first, $second] as $index => $scenario) {
                $this->assertSame($scenario['org']->id, $received[$index][0]['organization_id']);
                $this->assertSame($scenario['record']['id'], $received[$index][0]['match_id']);
                $this->assertSame(['changed_at', 'event_id', 'match_id', 'organization_id'], array_keys($received[$index][0]));
            }
            $this->assertSame(0, $client->wait(), $client->getErrorOutput());
        } finally {
            $client->stop(1);
            $server->stop(1);
            foreach (['sockets.json', 'auth.json', 'subscribed.json', 'received.json', 'sockets.json.pending', 'auth.json.pending', 'subscribed.json.pending', 'received.json.pending'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }
    }

    /** @return array<mixed> */
    private function exchange(string $path, Process $client): array
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (! $client->isRunning() || microtime(true) >= $deadline) {
                throw new \RuntimeException('Reverb client exchange failed: '.$client->getErrorOutput());
            }
            usleep(10000);
            clearstatcache(true, $path);
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
