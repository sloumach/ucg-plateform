<?php

namespace Tests\Feature;

use App\Modules\Media\Domain\MediaLink;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaLinkTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> */
    public static function links(): array
    {
        return ['yt short URL' => ['youtube', 'vod', 'https://youtu.be/abcdefghijk', 'https://www.youtube.com/watch?v=abcdefghijk'],
            'yt live' => ['youtube', 'stream', 'https://youtube.com/live/abcdefghijk', 'https://www.youtube.com/live/abcdefghijk'],
            'yt short' => ['youtube', 'clip', 'https://youtube.com/shorts/abcdefghijk', 'https://www.youtube.com/shorts/abcdefghijk'],
            'yt clip' => ['youtube', 'clip', 'https://www.youtube.com/clip/Ugkxabcdefghijk', 'https://www.youtube.com/clip/Ugkxabcdefghijk'],
            'twitch stream' => ['twitch', 'stream', 'https://twitch.tv/Demo_Channel', 'https://www.twitch.tv/demo_channel'],
            'twitch vod' => ['twitch', 'vod', 'https://www.twitch.tv/videos/123456', 'https://www.twitch.tv/videos/123456'],
            'twitch clip' => ['twitch', 'clip', 'https://www.twitch.tv/demo/clip/ExampleSlug', 'https://clips.twitch.tv/ExampleSlug']];
    }

    #[DataProvider('links')]
    public function test_canonical_official_references(string $provider, string $kind, string $input, string $expected): void
    {
        $this->assertSame($expected, MediaLink::canonical($provider, $kind, $input));
    }

    /** @return array<string, array{string}> */
    public static function unsafe(): array
    {
        return ['scheme' => ['http://youtube.com/watch?v=abcdefghijk'], 'script' => ['javascript:alert(1)'],
            'host suffix' => ['https://youtube.com.evil.test/watch?v=abcdefghijk'], 'credentials' => ['https://user@youtube.com/watch?v=abcdefghijk'],
            'port' => ['https://youtube.com:443/watch?v=abcdefghijk'], 'localhost' => ['https://127.0.0.1/watch?v=abcdefghijk'],
            'encoded path' => ['https://youtube.com/%77atch?v=abcdefghijk'], 'fragment' => ['https://youtube.com/watch?v=abcdefghijk#x'],
            'query duplicate' => ['https://youtube.com/watch?v=abcdefghijk&v=lmnopqrstuv'], 'tracking' => ['https://youtube.com/watch?v=abcdefghijk&si=secret'],
            'wrong provider' => ['https://twitch.tv/videos/123'], 'wrong id' => ['https://youtube.com/watch?v=bad'],
            'backslash' => ['https://youtube.com\@evil.test/watch?v=abcdefghijk'], 'wrong kind' => ['https://youtube.com/shorts/abcdefghijk']];
    }

    #[DataProvider('unsafe')]
    public function test_rejects_untrusted_reference_forms(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        MediaLink::canonical('youtube', 'vod', $input);
    }
}
