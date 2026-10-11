<?php

namespace Tests\Unit;

use App\Modules\Identity\Domain\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string, array{int, string}> */
    public static function rfcVectors(): array
    {
        return [
            'timestamp 59' => [59, '94287082'], 'timestamp 1111111109' => [1111111109, '07081804'],
            'timestamp 1111111111' => [1111111111, '14050471'], 'timestamp 1234567890' => [1234567890, '89005924'],
            'timestamp 2000000000' => [2000000000, '69279037'], 'timestamp 20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_matches_independent_rfc_6238_sha1_vectors(int $timestamp, string $expected): void
    {
        $this->assertSame($expected, (new Totp)->code(self::RFC_SECRET, $timestamp, 8));
    }

    public function test_window_accepts_adjacent_step_but_rejects_reuse_and_expired_codes(): void
    {
        $totp = new Totp;
        $this->assertSame(1, $totp->matchingStep(self::RFC_SECRET, '287082', 60, null));
        $this->assertNull($totp->matchingStep(self::RFC_SECRET, '287082', 60, 1));
        $this->assertNull($totp->matchingStep(self::RFC_SECRET, '287082', 90, null));
        $this->assertNull($totp->matchingStep(self::RFC_SECRET, '123', 59, null));
    }

    public function test_secret_generation_has_160_bits_and_round_trips_through_totp(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret);
        $this->assertSame(1, $totp->matchingStep($secret, $totp->code($secret, 59), 59, null));
    }
}
