<?php

namespace App\Modules\Contracts\Application\Data;

final readonly class DocumentDownload
{
    public function __construct(public string $contents, public string $filename) {}
}
