<?php

namespace App\Modules\Media\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $task_id
 * @property int $number
 * @property array<string, mixed> $snapshot */
final class WorkTaskRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'number' => 'integer', 'created_at' => 'datetime'];
    }
}
