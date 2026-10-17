<?php

namespace App\Modules\Scheduling\Domain;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeZone;
use InvalidArgumentException;

final class CalendarTime
{
    public const int MAX_WINDOW_DAYS = 93;

    public const int MAX_DURATION_SECONDS = 31 * 86400;

    public static function instant(string $value, string $timezone): CarbonImmutable
    {
        self::timezone($timezone);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $value);
        } catch (InvalidFormatException $error) {
            throw new InvalidArgumentException('Invalid calendar instant.', previous: $error);
        }
        // Round-trip the wall clock AND offset: gaps fail; folds require an explicit valid offset.
        if ($date === null || $date->setTimezone($timezone)->format('Y-m-d\\TH:i:sP') !== $value || (int) $date->format('Y') < 1) {
            throw new InvalidArgumentException('The local time and offset do not match the origin timezone.');
        }

        return $date->setTimezone('UTC');
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public static function window(string $fromDate, string $toDate, string $timezone): array
    {
        self::timezone($timezone);
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $timezone);
            $calendarFrom = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, 'UTC');
            $calendarTo = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, 'UTC');
        } catch (InvalidFormatException $error) {
            throw new InvalidArgumentException('Invalid calendar window.', previous: $error);
        }
        if ($from === null || $to === null || $calendarFrom === null || $calendarTo === null
            || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate
            || (int) $from->format('Y') < 1 || $calendarFrom->diffInDays($calendarTo, false) < 1
            || $calendarFrom->diffInDays($calendarTo, false) > self::MAX_WINDOW_DAYS) {
            throw new InvalidArgumentException('Calendar windows require 1 to 93 valid local dates.');
        }

        return [$from->setTimezone('UTC'), $to->setTimezone('UTC')];
    }

    private static function timezone(string $timezone): void
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('An IANA timezone is required.');
        }
    }
}
