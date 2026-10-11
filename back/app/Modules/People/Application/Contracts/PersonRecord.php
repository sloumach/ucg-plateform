<?php

namespace App\Modules\People\Application\Contracts;

use App\Support\Data\DataTransferObject;

/** An authorized, field-filtered snapshot; never reuse it as an access proof. */
final readonly class PersonRecord extends DataTransferObject
{
    /** @param array<string, mixed> $fields */
    public function __construct(public array $fields) {}
}
