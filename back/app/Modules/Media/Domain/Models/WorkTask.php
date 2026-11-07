<?php

namespace App\Modules\Media\Domain\Models;

use Database\Factories\WorkTaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $announcement_id
 * @property string $title
 * @property string|null $description
 * @property string|null $assignee_id
 * @property string|null $due_on
 * @property string $status
 * @property string $version
 * @property int $created_by */
final class WorkTask extends Model
{
    /** @use HasFactory<WorkTaskFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected static function newFactory(): WorkTaskFactory
    {
        return WorkTaskFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'description' => 'encrypted', 'created_by' => 'integer'];
    }
}
