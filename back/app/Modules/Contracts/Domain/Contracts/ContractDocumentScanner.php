<?php

namespace App\Modules\Contracts\Domain\Contracts;

use App\Modules\Contracts\Domain\ScanVerdict;

interface ContractDocumentScanner
{
    /** In-memory bytes only: adapters must neither expose paths nor persist clear document contents. */
    public function scan(string $contents): ScanVerdict;
}
