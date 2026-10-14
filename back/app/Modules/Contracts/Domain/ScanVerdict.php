<?php

namespace App\Modules\Contracts\Domain;

use InvalidArgumentException;

final readonly class ScanVerdict
{
    public function __construct(public string $status, public ?string $reason = null, public ?string $engine = null)
    {
        if (! in_array($status, ['clean', 'rejected', 'failed'], true) || ($status === 'clean') !== ($reason === null)
            || ($status === 'clean' && ($engine === null || trim($engine) === ''))
            || ($reason !== null && ! in_array($reason, DocumentSecurity::REASONS, true)) || ($engine !== null && strlen($engine) > 160)) {
            throw new InvalidArgumentException('A document scan requires a closed verdict.');
        }
    }
}
