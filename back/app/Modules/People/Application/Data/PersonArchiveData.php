<?php

namespace App\Modules\People\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class PersonArchiveData extends DataTransferObject
{
    public function __construct(public string $version, public bool $confirm) {}
}
