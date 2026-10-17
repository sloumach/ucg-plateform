<?php

namespace App\Modules\Scheduling\Domain;

use InvalidArgumentException;

final class CalendarLink
{
    public static function validate(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || $parts === false || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || preg_match('/[\\x00-\\x20\\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('A credential-free HTTPS link is required.');
        }

        return $url;
    }
}
