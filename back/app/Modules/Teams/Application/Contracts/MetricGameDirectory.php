<?php

namespace App\Modules\Teams\Application\Contracts;

interface MetricGameDirectory
{
    public function version(): string;

    public function lock(string $expected): void;

    /** @return array<string,mixed> */
    public function resolve(string $game, ?string $mode, ?string $role, ?string $release, bool $active): array;
}
