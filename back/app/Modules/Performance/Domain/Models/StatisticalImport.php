<?php

namespace App\Modules\Performance\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $organization_id
 * @property int $actor_user_id
 * @property string $artifact_id
 * @property string $fingerprint
 * @property string $delimiter
 * @property array<int,string> $headers
 * @property array<int,array<string,mixed>> $preview
 * @property int $row_count
 * @property int $valid_count
 * @property int $invalid_count
 * @property string $request_id
 * @property CarbonImmutable $created_at
 * @property-read StatisticalImportExecution|null $execution
 */
final class StatisticalImport extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'actor_user_id', 'artifact_id', 'fingerprint', 'headers', 'preview', 'request_id'];

    /** @return HasOne<StatisticalImportExecution,$this> */
    public function execution(): HasOne
    {
        return $this->hasOne(StatisticalImportExecution::class, 'import_id');
    }

    protected function casts(): array
    {
        return ['headers' => 'encrypted:array', 'preview' => 'encrypted:array', 'actor_user_id' => 'integer',
            'row_count' => 'integer', 'valid_count' => 'integer', 'invalid_count' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
