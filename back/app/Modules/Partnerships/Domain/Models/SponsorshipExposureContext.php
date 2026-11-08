<?php

namespace App\Modules\Partnerships\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $exposure_id
 * @property string|null $activity_id
 * @property string|null $media_id
 * @property string $source_version */
final class SponsorshipExposureContext extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    public function type(): string
    {
        return $this->activity_id === null ? 'content' : 'event';
    }

    public function sourceId(): string
    {
        return $this->activity_id ?? $this->media_id ?? throw new \LogicException('Exposure context has no source.');
    }
}
