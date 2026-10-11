<?php

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

/** RFC 6238: SHA-1, six digits, 30-second periods, one-period clock tolerance. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    public function generateSecret(): string
    {
        $bits = '';
        foreach (unpack('C*', random_bytes(20)) ?: [] as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= substr(self::ALPHABET, intval($chunk, 2), 1);
        }

        return $secret;
    }

    public function code(string $secret, int $timestamp, int $digits = 6): string
    {
        if ($timestamp < 0 || ! in_array($digits, [6, 8], true)) {
            throw new InvalidArgumentException('Unsupported TOTP parameters.');
        }
        $counter = intdiv($timestamp, self::PERIOD);
        $digest = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $this->decode($secret), true);
        $offset = ord($digest[19]) & 0x0F;
        $value = unpack('Nvalue', substr($digest, $offset, 4));
        $number = ($value['value'] ?? 0) & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function matchingStep(string $secret, string $code, int $timestamp, ?int $lastUsedStep): ?int
    {
        if (preg_match('/\A[0-9]{6}\z/D', $code) !== 1) {
            return null;
        }
        $current = intdiv($timestamp, self::PERIOD);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if ($step >= 0 && ($lastUsedStep === null || $step > $lastUsedStep)
                && hash_equals($this->code($secret, $step * self::PERIOD), $code)) {
                return $step;
            }
        }

        return null;
    }

    private function decode(string $secret): string
    {
        if (preg_match('/\A[A-Z2-7]{32}\z/D', $secret) !== 1) {
            throw new InvalidArgumentException('Invalid TOTP secret.');
        }
        $bits = '';
        foreach (str_split($secret) as $character) {
            $position = strpos(self::ALPHABET, $character);
            $bits .= str_pad(decbin($position === false ? 0 : $position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            $decoded .= chr(intval($chunk, 2) & 0xFF);
        }

        return $decoded;
    }
}
