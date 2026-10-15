<?php

namespace Tests\Fixtures;

use App\Modules\Contracts\Domain\Contracts\ContractReminderMailer;
use RuntimeException;

final class FakeContractReminderMailer implements ContractReminderMailer
{
    public bool $enabled = true;

    public bool $fail = false;

    /** @var list<array{email:string,key:string}> */
    public array $sent = [];

    public function available(): bool
    {
        return $this->enabled;
    }

    public function send(string $email, string $idempotencyKey): void
    {
        $this->sent[] = ['email' => $email, 'key' => $idempotencyKey];
        if ($this->fail) {
            throw new RuntimeException('SMTP acknowledgement lost');
        }
    }
}
