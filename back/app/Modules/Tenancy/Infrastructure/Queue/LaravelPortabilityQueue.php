<?php

namespace App\Modules\Tenancy\Infrastructure\Queue;

use App\Modules\Tenancy\Domain\Contracts\PortabilityQueue;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

final readonly class LaravelPortabilityQueue implements PortabilityQueue
{
    public function __construct(private Dispatcher $bus, private Repository $config, private Application $app) {}

    public function export(string $organizationId, string $operationId): void
    {
        $connection = $this->config->get('tenancy.schedule_connection') ?? $this->config->get('queue.default');
        $driver = is_string($connection) ? $this->config->get('queue.connections.'.$connection.'.driver') : null;
        if (! is_string($connection) || (! in_array($driver, ['database', 'redis'], true)
            && ! ($driver === 'sync' && $this->app->runningUnitTests()))) {
            throw new LogicException('Operator exports require a durable queue.');
        }
        $this->bus->dispatch((new BuildOrganizationExport($organizationId, $operationId))->onConnection($connection));
    }
}
