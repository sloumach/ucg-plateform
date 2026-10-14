<?php

namespace App\Modules\Contracts\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string $title
 * @property CarbonImmutable|null $due_on
 * @property string $status
 * @property string $version
 * @property int $position
 */
final class ContractObligation extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'due_on' => 'immutable_date', 'position' => 'integer'];
    }
}
