<?php

namespace App\Modules\People\Application\Data;

use App\Support\Data\DataTransferObject;

/** Patch semantics preserve administrative fields omitted by a sports-only editor. */
final readonly class PersonData extends DataTransferObject
{
    /** @param array<string, mixed> $attributes Server-validated again at the service boundary. */
    public function __construct(public array $attributes, public ?string $version = null) {}
}
