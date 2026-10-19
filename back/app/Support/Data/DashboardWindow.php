<?php

namespace App\Support\Data;

use Carbon\CarbonImmutable;

final readonly class DashboardWindow extends DataTransferObject
{
    public const MAX_DAYS = 31;

    public function __construct(public string $from, public string $to, public string $timezone, public int $page = 1) {}

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from, $this->timezone)->startOfDay()->utc();
    }

    public function end(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to, $this->timezone)->addDay()->startOfDay()->utc();
    }

    public function exclusiveTo(): string
    {
        return $this->end()->setTimezone($this->timezone)->toDateString();
    }
}
