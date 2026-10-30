<?php

namespace App\Contracts\Operations;

interface BackupEvidence
{
    /** @return array<string, mixed> */
    public function read(): array;
}
