<?php

namespace Tests\Unit;

use App\Modules\Scheduling\Domain\CalendarIcs;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CalendarIcsTest extends TestCase
{
    public function test_text_is_escaped_lines_fold_at_75_octets_without_splitting_unicode_and_dates_are_utc(): void
    {
        $start = CarbonImmutable::parse('2026-10-25T02:30:00+02:00');
        $title = str_repeat('é🎮', 30).",;\\\r\nATTENDEE:injected\x00";
        $body = (new CalendarIcs)->render('feed', [['id' => 'event', 'title' => $title, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'updated_at' => $start]]);
        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
            $this->assertFalse(str_starts_with($line, 'ATTENDEE:'));
        }
        $unfolded = str_replace("\r\n ", '', $body);
        $this->assertStringContainsString('SUMMARY:'.str_repeat('é🎮', 30).'\\,\\;\\\\\\nATTENDEE:injected', $unfolded);
        $this->assertStringContainsString('DTSTART:20261025T003000Z', $body);
        $this->assertStringContainsString('DTEND:20261025T013000Z', $body);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $body);
        $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'));
        $this->assertStringNotContainsString("\x00", $body);
    }

    public function test_uid_is_stable_per_feed_and_does_not_reveal_original_identifiers(): void
    {
        $date = CarbonImmutable::parse('2026-10-17T10:00:00Z');
        $event = ['id' => 'event-secret', 'title' => 'Titre', 'starts_at' => $date, 'ends_at' => $date->addHour(), 'updated_at' => $date];
        $ics = new CalendarIcs;
        $one = $ics->render('feed-secret', [$event]);
        if (preg_match('/UID:(.*?)(?=\r\nDTSTAMP)/s', str_replace("\r\n ", '', $one), $uid) !== 1) {
            $this->fail('Each event requires a stable UID.');
        }
        $this->assertStringNotContainsString('event-secret', $one);
        $this->assertStringNotContainsString('feed-secret', $one);
        $event['updated_at'] = $date->addDay();
        $event['title'] = 'Modifié';
        $this->assertStringContainsString($uid[1], str_replace("\r\n ", '', $ics->render('feed-secret', [$event])));
        $this->assertStringNotContainsString($uid[1], str_replace("\r\n ", '', $ics->render('other-feed', [$event])));
    }
}
