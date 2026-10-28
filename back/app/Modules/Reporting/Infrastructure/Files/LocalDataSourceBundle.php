<?php

namespace App\Modules\Reporting\Infrastructure\Files;

use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use Illuminate\Validation\ValidationException;

/** Explicit local bundle only: no recursive discovery, URL, stream wrapper or executable loading. */
final class LocalDataSourceBundle implements DataSourceBundle
{
    public function manifest(string $path): array
    {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0") || str_contains($path, '://')
            || str_starts_with($path, '\\\\') || str_starts_with($path, '//') || is_link($path)) {
            $this->invalid();
        }
        $real = $this->resolve($path);
        if ($real === false || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'json') {
            $this->invalid();
        }

        return ['root' => dirname($real), 'contents' => $this->read(dirname($real), basename($real), 65536)];
    }

    public function read(string $root, string $relative, int $maximumBytes): string
    {
        if ($maximumBytes < 1 || $maximumBytes > 1048576 || $relative === '' || strlen($relative) > 240
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $relative) !== 1
            || $this->resolve($root) !== $root) {
            $this->invalid();
        }
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.') || preg_match('/[. ]$/D', $segment) === 1) {
                $this->invalid();
            }
            $path .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($path) || ! $this->atExpectedLocation($path)) {
                $this->invalid();
            }
        }
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > $maximumBytes) {
            $this->invalid();
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->invalid();
        }
        try {
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                $this->invalid();
            }
            $opened = fstat($handle);
            $contents = stream_get_contents($handle, $maximumBytes + 1);
            $after = fstat($handle);
            if ($opened === false || $after === false || $contents === false || strlen($contents) > $maximumBytes
                || ($opened['mode'] & 0170000) !== 0100000 || ! $this->atExpectedLocation($path)
                || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']
                || $opened['size'] !== $after['size'] || $opened['mtime'] !== $after['mtime'] || strlen($contents) !== $after['size']) {
                $this->invalid();
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /** Filesystem state may change between validation and reading.
     * @phpstan-impure
     */
    private function resolve(string $path): string|false
    {
        clearstatcache(true, $path);

        return realpath($path);
    }

    /** @phpstan-impure */
    private function atExpectedLocation(string $expected): bool
    {
        $actual = $this->resolve($expected);
        if ($actual === false) {
            return false;
        }

        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($actual, $expected) === 0 : $actual === $expected;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['bundle' => __('data_audit.invalid_bundle')]);
    }
}
