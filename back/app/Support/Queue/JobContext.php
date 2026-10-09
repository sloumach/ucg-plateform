<?php

namespace App\Support\Queue;

use InvalidArgumentException;

final readonly class JobContext
{
    public function __construct(
        public string $requestId,
        public ?string $tenantId = null,
    ) {
        if (trim($this->requestId) === '') {
            throw new InvalidArgumentException('A queued job requires a request identifier.');
        }

        if ($this->tenantId !== null && trim($this->tenantId) === '') {
            throw new InvalidArgumentException('A tenant identifier cannot be empty.');
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['request:'.$this->requestId];

        if ($this->tenantId !== null) {
            $tags[] = 'tenant:'.$this->tenantId;
        }

        return $tags;
    }
}
