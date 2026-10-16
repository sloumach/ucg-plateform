<?php

namespace App\Modules\Media\Domain;

use InvalidArgumentException;

/** Syntactic allowlist only: validating a reference never contacts its provider. */
final class MediaLink
{
    public static function canonical(string $provider, string $kind, string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment']) || preg_match('/[\s\\\\%]/u', $url) === 1) {
            throw new InvalidArgumentException('Invalid media reference.');
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        if ($provider === 'youtube') {
            if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
                if ($path === '/watch' && preg_match('/^v=([A-Za-z0-9_-]{11})$/D', $query, $match) === 1 && in_array($kind, ['stream', 'vod'], true)) {
                    return 'https://www.youtube.com/watch?v='.$match[1];
                }
                if ($query === '' && preg_match('~^/(live|shorts)/([A-Za-z0-9_-]{11})/?$~D', $path, $match) === 1
                    && (($match[1] === 'live' && $kind === 'stream') || ($match[1] === 'shorts' && $kind === 'clip'))) {
                    return 'https://www.youtube.com/'.$match[1].'/'.$match[2];
                }
                if ($kind === 'clip' && $query === '' && preg_match('~^/clip/([A-Za-z0-9_-]{10,100})/?$~D', $path, $match) === 1) {
                    return 'https://www.youtube.com/clip/'.$match[1];
                }
            }
            if ($host === 'youtu.be' && $query === '' && in_array($kind, ['stream', 'vod'], true) && preg_match('~^/([A-Za-z0-9_-]{11})/?$~D', $path, $match) === 1) {
                return 'https://www.youtube.com/watch?v='.$match[1];
            }
        }
        if ($provider === 'twitch' && $query === '') {
            if (in_array($host, ['twitch.tv', 'www.twitch.tv'], true)) {
                if ($kind === 'stream' && preg_match('~^/([A-Za-z0-9_]{3,25})/?$~D', $path, $match) === 1
                    && ! in_array(strtolower($match[1]), ['directory', 'downloads', 'settings', 'subscriptions', 'wallet', 'search', 'videos'], true)) {
                    return 'https://www.twitch.tv/'.strtolower($match[1]);
                }
                if ($kind === 'vod' && preg_match('~^/videos/([0-9]{1,20})/?$~D', $path, $match) === 1) {
                    return 'https://www.twitch.tv/videos/'.$match[1];
                }
                if ($kind === 'clip' && preg_match('~^/[A-Za-z0-9_]{3,25}/clip/([A-Za-z0-9_-]{3,100})/?$~D', $path, $match) === 1) {
                    return 'https://clips.twitch.tv/'.$match[1];
                }
            }
            if ($host === 'clips.twitch.tv' && $kind === 'clip' && preg_match('~^/([A-Za-z0-9_-]{3,100})/?$~D', $path, $match) === 1) {
                return 'https://clips.twitch.tv/'.$match[1];
            }
        }
        throw new InvalidArgumentException('Unsupported media reference.');
    }
}
