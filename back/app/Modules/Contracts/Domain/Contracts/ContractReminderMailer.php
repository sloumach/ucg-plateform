<?php

namespace App\Modules\Contracts\Domain\Contracts;

interface ContractReminderMailer
{
    public function available(): bool;

    /** A stable key permits a future provider API to support server-side idempotency.
     * Returns only after transport acceptance. Exceptions have an ambiguous delivery outcome.
     */
    public function send(string $email, string $idempotencyKey): void;
}
