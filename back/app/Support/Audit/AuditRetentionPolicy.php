<?php

namespace App\Support\Audit;

use Illuminate\Contracts\Config\Repository;
use LogicException;

final readonly class AuditRetentionPolicy
{
    public function __construct(private Repository $config) {}

    /** @return array{mode:string,purge_enabled:bool} */
    public function description(): array
    {
        if ($this->config->get('audit.retention.mode') !== 'unlimited' || $this->config->get('audit.retention.purge_enabled') !== false) {
            throw new LogicException('IAM-006 only permits unlimited retention without purge.');
        }

        return ['mode' => 'unlimited', 'purge_enabled' => false];
    }
}
