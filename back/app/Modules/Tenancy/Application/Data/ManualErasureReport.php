<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;
use JsonSerializable;

final readonly class ManualErasureReport extends DataTransferObject implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
