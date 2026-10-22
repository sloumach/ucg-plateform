<?php

namespace App\Modules\Reporting\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property int $actor_user_id
 * @property string $request_key
 * @property string $card
 * @property string $view
 * @property string $timezone
 * @property string $from_date
 * @property string $to_date
 * @property string $status
 * @property string|null $artifact_id
 * @property int|null $source_version
 * @property int|null $row_count
 * @property string|null $digest
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $generated_at */
final class ReportExport extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['artifact_id', 'digest', 'request_key', 'source_version'];

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'source_version' => 'integer', 'row_count' => 'integer',
            'expires_at' => 'immutable_datetime', 'generated_at' => 'immutable_datetime'];
    }
}
