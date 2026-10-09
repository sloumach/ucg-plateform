<?php

namespace App\Contracts\Storage;

interface ArtifactStorage
{
    public function putForTenant(string $tenantId, string $relativePath, string $contents): string;
}
