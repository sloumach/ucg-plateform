<?php

namespace App\Support\Operations;

use App\Contracts\Operations\DependencyTransport;
use GuzzleHttp\Client;
use Illuminate\Contracts\Config\Repository;
use Pusher\Pusher;
use RuntimeException;

final readonly class SocketDependencyTransport implements DependencyTransport
{
    public function __construct(private Repository $config) {}

    public function connections(): int
    {
        $connection = $this->config->get('broadcasting.connections.reverb');
        if (! is_array($connection)) {
            throw new RuntimeException('REALTIME_CONFIGURATION_INVALID');
        }
        foreach (['key', 'secret', 'app_id'] as $key) {
            if (! isset($connection[$key]) || ! is_string($connection[$key]) || $connection[$key] === '') {
                throw new RuntimeException('REALTIME_CONFIGURATION_INVALID');
            }
        }
        $options = $connection['options'] ?? null;
        if (! is_array($options) || ! is_string($options['host'] ?? null)
            || preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/', $options['host']) !== 1
            || ! in_array($options['scheme'] ?? null, ['http', 'https'], true)
            || preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $connection['app_id']) !== 1) {
            throw new RuntimeException('REALTIME_CONFIGURATION_INVALID');
        }
        $client = new Pusher($connection['key'], $connection['secret'], $connection['app_id'], [...$options, 'timeout' => 3],
            new Client(['timeout' => 3, 'connect_timeout' => 2, 'allow_redirects' => false]));
        $result = $client->get('/connections');
        $count = is_object($result) ? ($result->connections ?? null) : null;
        if (! is_int($count) || $count < 0) {
            throw new RuntimeException('REALTIME_REPLY_INVALID');
        }

        return $count;
    }

    public function ping(string $service, string $host, int $port, bool $tls): bool
    {
        if (! in_array($service, ['smtp', 'scanner'], true) || preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/', $host) !== 1
            || $port < 1 || $port > 65535) {
            throw new RuntimeException('DEPENDENCY_TARGET_INVALID');
        }
        $socket = @stream_socket_client(($tls ? 'tls://' : 'tcp://').$host.':'.$port, $errorCode, $errorMessage, 2);
        if ($socket === false) {
            return false;
        }
        try {
            stream_set_timeout($socket, 2);
            if ($service === 'scanner' && @fwrite($socket, "zPING\0") !== 6) {
                return false;
            }
            $reply = @fread($socket, 512);
            if (! is_string($reply) || stream_get_meta_data($socket)['timed_out']) {
                return false;
            }

            return $service === 'scanner' ? trim($reply, "\0\r\n ") === 'PONG' : str_starts_with($reply, '220 ');
        } finally {
            fclose($socket);
        }
    }
}
