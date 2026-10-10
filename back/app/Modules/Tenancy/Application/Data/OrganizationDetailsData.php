<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class OrganizationDetailsData extends DataTransferObject
{
    public function __construct(
        public string $name,
        public string $timezone,
        public string $language,
        public string $country,
        public int $weekStartsOn = 1,
        public string $dateFormat = 'd/m/Y',
    ) {}

    /** @return array{name: string, timezone: string, language: string, country: string} */
    public function attributes(): array
    {
        return ['name' => $this->name, 'timezone' => $this->timezone, 'language' => $this->language, 'country' => $this->country];
    }

    /** @return array{week_starts_on: int, date_format: string} */
    public function settings(): array
    {
        return ['week_starts_on' => $this->weekStartsOn, 'date_format' => $this->dateFormat];
    }
}
