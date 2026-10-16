<?php

namespace App\Modules\Media\Domain\Models;

use Database\Factories\MediaItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property string $id
 * @property string $organization_id
 * @property string $team_id
 * @property string $title
 * @property string $kind
 * @property string $provider
 * @property string $url
 * @property string $status
 * @property bool $is_scrim
 * @property string $visibility
 * @property string $version
 * @property Carbon $status_observed_at
 * @property Carbon|null $publish_at
 * @property Carbon|null $archived_at
 */
class MediaItem extends Model
{
    /** @use HasFactory<MediaItemFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'created_by'];

    protected static function newFactory(): MediaItemFactory
    {
        return MediaItemFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_scrim' => 'boolean', 'status_observed_at' => 'datetime', 'publish_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function effectiveVisibility(): string
    {
        return $this->archived_at !== null || ($this->publish_at !== null && $this->publish_at->isFuture()) ? 'private' : $this->visibility;
    }
}
