<?php

namespace App\Modules\Scheduling\Domain;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;

/** RFC 5545 minimal projection; no identities or operational notes enter this encoder. */
final class CalendarIcs
{
    public const int MAX_EVENTS = 500;

    /** @param list<array{id:string,title:string,starts_at:CarbonImmutable,ends_at:CarbonImmutable,updated_at:CarbonImmutable}> $events */
    public function render(string $feedId, array $events): string
    {
        if (count($events) > self::MAX_EVENTS) {
            throw new DomainConflictException(__('calendar.feed_limit'), 'CALENDAR_FEED_LIMIT_EXCEEDED');
        }
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//UCG//Private Calendar//FR', 'CALSCALE:GREGORIAN'];
        foreach ($events as $event) {
            array_push($lines, 'BEGIN:VEVENT', 'UID:'.hash('sha256', $feedId.'|'.$event['id']).'@ucg.invalid',
                'DTSTAMP:'.$this->utc($event['updated_at']), 'DTSTART:'.$this->utc($event['starts_at']), 'DTEND:'.$this->utc($event['ends_at']),
                'SUMMARY:'.$this->text($event['title']), 'CLASS:PRIVATE', 'TRANSP:OPAQUE', 'END:VEVENT');
        }
        $lines[] = 'END:VCALENDAR';
        $body = implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
        if (strlen($body) > 524288) {
            throw new DomainConflictException(__('calendar.feed_limit'), 'CALENDAR_FEED_LIMIT_EXCEEDED');
        }

        return $body;
    }

    private function utc(CarbonImmutable $date): string
    {
        return $date->utc()->format('Ymd\THis\Z');
    }

    private function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    private function fold(string $line): string
    {
        $parts = [];
        $width = 75;
        while (strlen($line) > $width) {
            $part = mb_strcut($line, 0, $width, 'UTF-8');
            $parts[] = $part;
            $line = substr($line, strlen($part));
            $width = 74;
        }
        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
