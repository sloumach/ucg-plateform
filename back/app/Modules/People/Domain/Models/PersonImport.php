<?php

namespace App\Modules\People\Domain\Models;

use Database\Factories\PersonImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property int $actor_user_id
 * @property int|null $execution_actor_id
 * @property string $artifact_id
 * @property string $fingerprint
 * @property string $delimiter
 * @property string $status
 * @property string $version
 * @property string|null $confirmed_version
 * @property array<int,string> $headers
 * @property array<string,int> $mapping
 * @property array<string,string> $defaults
 * @property int $row_count
 * @property string|null $failure_code
 */
final class PersonImport extends Model
{
    /** @use HasFactory<PersonImportFactory> */
    use HasFactory, HasUuids;

    protected $table = 'person_imports';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'artifact_id', 'fingerprint', 'headers', 'actor_user_id', 'execution_actor_id', 'confirmed_version'];

    protected static function newFactory(): PersonImportFactory
    {
        return PersonImportFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['headers' => 'encrypted:array', 'mapping' => 'array', 'defaults' => 'array', 'actor_user_id' => 'integer', 'execution_actor_id' => 'integer', 'row_count' => 'integer'];
    }
}
