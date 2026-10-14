<?php

namespace App\Modules\Contracts\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class DocumentDownloadLink extends DataTransferObject
{
    public function __construct(public string $url, public string $expires_at, public string $filename) {}

    /** @return array{url: string, expires_at: string, filename: string} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'expires_at' => $this->expires_at, 'filename' => $this->filename];
    }
}
