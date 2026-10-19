<?php

namespace App\Modules\Competition\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $competition_id
 * @property string $revision_id
 * @property string $reference_id
 * @property string $match_id */
final class CompetitionOutcomeMatch extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'match_id'];
}
