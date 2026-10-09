<?php

namespace Tests\Fixtures\Jobs;

use App\Contracts\Storage\ArtifactStorage;
use App\Support\Queue\JobContext;
use App\Support\Queue\QueueName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LogicException;

final class StoreTenantArtifact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly JobContext $context,
        public readonly string $relativePath,
        public readonly string $contents,
    ) {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function handle(ArtifactStorage $storage): void
    {
        if ($this->context->tenantId === null) {
            throw new LogicException('A tenant-aware job requires a tenant identifier.');
        }

        $storage->putForTenant(
            $this->context->tenantId,
            $this->relativePath,
            $this->contents,
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->context->tags();
    }
}
