<?php

namespace Tests\Feature;

use App\Modules\Scheduling\Domain\CalendarLink;
use App\Modules\Scheduling\Domain\CalendarTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalendarTimeTest extends TestCase
{
    /** @return array<string,array{string,string,string}> */
    public static function instants(): array
    {
        return ['summer' => ['2026-07-01T10:00:00+02:00', 'Europe/Paris', '2026-07-01T08:00:00+00:00'],
            'winter' => ['2026-11-01T10:00:00+01:00', 'Europe/Paris', '2026-11-01T09:00:00+00:00'],
            'first folded hour' => ['2026-10-25T02:30:00+02:00', 'Europe/Paris', '2026-10-25T00:30:00+00:00'],
            'second folded hour' => ['2026-10-25T02:30:00+01:00', 'Europe/Paris', '2026-10-25T01:30:00+00:00'],
            'half hour' => ['2026-10-18T10:00:00+05:30', 'Asia/Kolkata', '2026-10-18T04:30:00+00:00']];
    }

    #[DataProvider('instants')]
    public function test_explicit_offsets_preserve_the_intended_instant(string $value, string $zone, string $expected): void
    {
        $this->assertSame($expected, CalendarTime::instant($value, $zone)->toIso8601String());
    }

    /** @return array<string,array{string,string}> */
    public static function invalid(): array
    {
        return ['gap' => ['2026-03-29T02:30:00+01:00', 'Europe/Paris'], 'wrong offset' => ['2026-07-01T10:00:00+01:00', 'Europe/Paris'],
            'unknown zone' => ['2026-01-01T00:00:00+00:00', 'Bad/Zone'], 'no offset' => ['2026-01-01T00:00:00', 'UTC']];
    }

    #[DataProvider('invalid')]
    public function test_invalid_wall_time_or_timezone_is_rejected(string $value, string $zone): void
    {
        $this->expectException(InvalidArgumentException::class);
        CalendarTime::instant($value, $zone);
    }

    public function test_day_windows_follow_local_midnights_across_both_dst_transitions(): void
    {
        [$from,$to] = CalendarTime::window('2026-03-29', '2026-03-30', 'Europe/Paris');
        $this->assertSame('2026-03-28T23:00:00+00:00', $from->toIso8601String());
        $this->assertSame('2026-03-29T22:00:00+00:00', $to->toIso8601String());
        [$from,$to] = CalendarTime::window('2026-10-25', '2026-10-26', 'Europe/Paris');
        $this->assertSame('2026-10-24T22:00:00+00:00', $from->toIso8601String());
        $this->assertSame('2026-10-25T23:00:00+00:00', $to->toIso8601String());
    }

    /** @return array<string,array{string,string,string}> */
    public static function invalidWindows(): array
    {
        return ['empty' => ['2026-01-01', '2026-01-01', 'UTC'], 'reverse' => ['2026-01-02', '2026-01-01', 'UTC'],
            'too wide' => ['2026-01-01', '2026-04-05', 'UTC'], 'skipped local day' => ['2011-12-30', '2011-12-31', 'Pacific/Apia']];
    }

    #[DataProvider('invalidWindows')]
    public function test_unbounded_or_invalid_local_date_window_is_rejected(string $from, string $to, string $zone): void
    {
        $this->expectException(InvalidArgumentException::class);
        CalendarTime::window($from, $to, $zone);
    }

    /** @return array<string,array{string}> */
    public static function badLinks(): array
    {
        return ['script' => ['javascript:alert(1)'], 'http' => ['http://example.test/room'], 'credentials' => ['https://u:p@example.test/room'],
            'port' => ['https://example.test:443/room'], 'control' => ["https://example.test/room\nnext"]];
    }

    #[DataProvider('badLinks')]
    public function test_calendar_links_cannot_embed_credentials_or_active_schemes(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        CalendarLink::validate($url);
    }

    public function test_https_invitation_parameters_are_preserved_without_remote_lookup(): void
    {
        $this->assertSame('https://meeting.example.test/room?token=abc#join', CalendarLink::validate('https://meeting.example.test/room?token=abc#join'));
    }
}
