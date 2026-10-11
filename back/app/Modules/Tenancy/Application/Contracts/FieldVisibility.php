<?php

namespace App\Modules\Tenancy\Application\Contracts;

/** Server-owned output schema. Nested data always needs its own allow-list. */
final readonly class FieldVisibility
{
    /** @param array<string, self> $children */
    public function __construct(public string $permission, public array $children = [], public bool $collection = false) {}
}
