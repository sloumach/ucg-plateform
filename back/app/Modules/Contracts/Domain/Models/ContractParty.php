<?php

namespace App\Modules\Contracts\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string $kind
 * @property string|null $person_id
 * @property string $name
 * @property int $position
 */
final class ContractParty extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['name' => 'encrypted', 'position' => 'integer'];
    }
}
