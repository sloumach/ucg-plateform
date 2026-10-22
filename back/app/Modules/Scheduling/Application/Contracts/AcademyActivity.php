<?php

namespace App\Modules\Scheduling\Application\Contracts;

use App\Support\Data\DataTransferObject;
use Carbon\CarbonImmutable;

final readonly class AcademyActivity extends DataTransferObject
{
    public function __construct(public string $id, public string $version, public string $label,
        public CarbonImmutable $startsAt, public CarbonImmutable $endsAt, public string $timezone) {}
}
