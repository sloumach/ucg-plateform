<?php

namespace App\Modules\People\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $import_id
 * @property int $line
 * @property string $status
 * @property array<string,mixed> $values
 * @property list<string> $errors
 * @property string|null $person_id
 */
final class PersonImportRow extends Model
{
    use HasUuids;

    protected $table = 'person_import_rows';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'values', 'errors'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['line' => 'integer', 'values' => 'encrypted:array', 'errors' => 'encrypted:array'];
    }
}
