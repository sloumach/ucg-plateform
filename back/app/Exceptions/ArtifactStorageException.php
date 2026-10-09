<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ArtifactStorageException extends RuntimeException
{
    public static function writeFailed(string $path, ?Throwable $previous = null): self
    {
        return new self(
            message: "Unable to persist artifact at [{$path}].",
            previous: $previous,
        );
    }
}
