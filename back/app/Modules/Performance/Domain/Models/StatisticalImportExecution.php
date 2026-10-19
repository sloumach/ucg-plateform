<?php

namespace App\Modules\Performance\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $import_id
 * @property string $status
 * @property string $version
 * @property int $processed_count
 * @property array<int,array{status:string,code:?string}> $results
 * @property string|null $failure_code
 */
final class StatisticalImportExecution extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'import_id', 'results'];

    protected function casts(): array
    {
        return ['processed_count' => 'integer', 'results' => 'encrypted:array'];
    }
}
