<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class SupportAccessData extends DataTransferObject
{
    /** @param list<string> $actions */
    public function __construct(public string $reason, public int $durationMinutes, public array $actions) {}
}
