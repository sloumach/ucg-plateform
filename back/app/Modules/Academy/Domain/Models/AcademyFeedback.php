<?php

namespace App\Modules\Academy\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $objective_id
 * @property string $cycle_id
 * @property string $audience
 * @property string $content
 * @property string|null $justification
 * @property string $status
 * @property int $author_user_id
 * @property string $version */
final class AcademyFeedback extends Model
{
    use HasUuids;

    protected $table = 'academy_feedback';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'justification'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['content' => 'encrypted', 'justification' => 'encrypted', 'author_user_id' => 'integer'];
    }
}
