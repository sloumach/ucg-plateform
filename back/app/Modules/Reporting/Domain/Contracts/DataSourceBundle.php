<?php

namespace App\Modules\Reporting\Domain\Contracts;

interface DataSourceBundle
{
    /** @return array{root: string, contents: string} */
    public function manifest(string $path): array;

    public function read(string $root, string $relative, int $maximumBytes): string;
}
