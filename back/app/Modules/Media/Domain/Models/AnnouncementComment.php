<?php

namespace App\Modules\Media\Domain\Models;

use Database\Factories\AnnouncementCommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property string $id
 * @property string $organization_id
 * @property string $announcement_id
 * @property string $body
 * @property int $created_by
 * @property Carbon $created_at
 */
final class AnnouncementComment extends Model
{
    /** @use HasFactory<AnnouncementCommentFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'created_by'];

    protected static function newFactory(): AnnouncementCommentFactory
    {
        return AnnouncementCommentFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_by' => 'integer'];
    }
}
