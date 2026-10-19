<?php

namespace App\Modules\Performance\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $import_id
 * @property string $observation_id
 * @property string $identity_key
 * @property string $payload_hash
 */
final class StatisticalImportReceipt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'identity_key', 'payload_hash'];
}
