<?php

namespace App\Modules\Tenancy\Domain\Data;

final readonly class PortabilityArchiveData
{
    public function __construct(public int $bytes, public string $sha256, public int $rows, public int $files) {}
}
