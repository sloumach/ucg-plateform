<?php

namespace App\Modules\People\Domain\Models;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait PersonLifecycleIdentity
{
    /** @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forPerson(Builder $query, string $organizationId, string $personId): Builder
    {
        return $query->where('organization_id', strtolower($organizationId))->where('person_id', strtolower($personId));
    }

    protected static function bootPersonLifecycleIdentity(): void
    {
        static::deleting(fn () => throw new DomainConflictException(__('people_lifecycle.history'), 'PERSON_LIFECYCLE_HISTORY_RETAINED'));
        static::updating(function (self $record): void {
            if ($record instanceof PersonDeparture || $record->isDirty(['id', 'organization_id', 'person_id', 'kind', 'role_key'])) {
                throw new DomainConflictException(__('people_lifecycle.immutable'), 'PERSON_LIFECYCLE_IDENTITY_IMMUTABLE');
            }
        });
    }
}
