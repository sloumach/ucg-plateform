<?php

namespace App\Contracts\Operations;

use App\Support\Operations\RuntimeInspection;

interface AlertLedger
{
    public function synchronize(string $namespace, RuntimeInspection $inspection, int $escalationSeconds): void;

    /** @return list<array<string, mixed>> */
    public function listing(string $namespace, int $page): array;

    public function acknowledge(string $namespace, string $id, int $version, string $incident): void;
}
