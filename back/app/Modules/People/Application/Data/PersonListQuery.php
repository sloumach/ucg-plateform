<?php

namespace App\Modules\People\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class PersonListQuery extends DataTransferObject
{
    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes = []) {}
}
