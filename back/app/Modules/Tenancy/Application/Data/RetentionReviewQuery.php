<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class RetentionReviewQuery extends DataTransferObject
{
    public CarbonImmutable $asOf;

    public function __construct(public string $section, CarbonImmutable $asOf, public int $page = 1, public int $perPage = 25)
    {
        if (! in_array($section, ['inventory', 'people'], true) || $page < 1 || $page > 10000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Invalid bounded retention review query.');
        }
        $this->asOf = $asOf->utc()->startOfDay();
    }
}
