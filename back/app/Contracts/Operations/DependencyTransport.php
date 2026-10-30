<?php

namespace App\Contracts\Operations;

interface DependencyTransport
{
    public function ping(string $service, string $host, int $port, bool $tls): bool;

    public function connections(): int;
}
