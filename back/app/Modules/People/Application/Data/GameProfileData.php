<?php

namespace App\Modules\People\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class GameProfileData extends DataTransferObject
{
    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes, public ?string $version = null) {}
}
