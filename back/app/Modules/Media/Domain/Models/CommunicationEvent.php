<?php

namespace App\Modules\Media\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property string $id
 * @property string $organization_id
 * @property string $announcement_id
 * @property string|null $comment_id
 * @property string $kind
 * @property Carbon $created_at
 * @property-read Announcement $announcement
 */
final class CommunicationEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'actor_user_id'];

    /** @return BelongsTo<Announcement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
