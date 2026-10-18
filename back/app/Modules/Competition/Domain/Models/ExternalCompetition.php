<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ExternalCompetitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $game_id
 * @property string $game_key
 * @property string $game_name
 * @property string $name
 * @property string $organizer
 * @property string $region
 * @property string $season
 * @property ?string $website_url
 * @property list<array{name:string,role:?string,email:?string,phone:?string}> $contacts
 * @property string $status
 * @property string $version
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at */
final class ExternalCompetition extends Model
{
    /** @use HasFactory<ExternalCompetitionFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'name', 'organizer', 'website_url', 'contacts'];

    protected static function newFactory(): ExternalCompetitionFactory
    {
        return ExternalCompetitionFactory::new();
    }

    /**
     * @return array<string,string> */
    protected function casts(): array
    {
        return ['name' => 'encrypted', 'organizer' => 'encrypted', 'website_url' => 'encrypted', 'contacts' => 'encrypted:array', 'created_by' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
