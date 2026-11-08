<?php

namespace App\Modules\Scheduling\Infrastructure\Resolution;

use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentCalendarVisibility;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeSource;
use App\Modules\Tenancy\Application\Contracts\ContextualAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class EventAuthorizationScopes implements AuthorizationScopeSource
{
    public function __construct(private ConnectionInterface $database, private CalendarPeopleDirectory $people, private CalendarTeamsDirectory $teams, private TenantAuthorization $authorization, private EloquentCalendarVisibility $visibility, private ContextualAuthorization $scoped) {}

    public function exists(string $organizationId, string $type, string $id): bool
    {
        return $type === 'event' && $this->sources($organizationId, $type)->where('id', $id)->exists();
    }

    public function availableTypes(): array
    {
        return ['event'];
    }

    public function choices(TenantContext $context, string $type, int $page, int $perPage, ?Builder $visible = null): LengthAwarePaginator
    {
        $organizationId = $context->organizationId;
        if ($visible === null) {
            $viewer = $this->people->viewer($context);
            $audience = new CalendarAudience($context->actorUserId, $viewer['person_id'], $this->teams->currentIds($context), explicitActivityIds: $this->scoped->ids($context, 'event', 'calendar.read', true));
            $query = $this->visibility->query($organizationId, $audience, $this->authorization->allows($context, 'calendar.write'));
        } else {
            $query = CalendarActivity::query()->where('organization_id', $organizationId)->whereIn('id', $visible);
        }

        return $query->whereIn('id', $this->sources($organizationId, $type)->select('id'))
            ->orderBy('starts_at')->orderBy('id')->paginate($perPage, ['id', 'title'], 'page', $page)
            ->through(function ($activity): \stdClass {
                $option = new \stdClass;
                $option->id = $activity->id;
                $option->label = $activity->title;

                return $option;
            });
    }

    public function sources(string $organizationId, string $type): Builder
    {
        if ($type !== 'event') {
            throw new ModelNotFoundException;
        }

        return $this->database->table('calendar_activities')->where('organization_id', $organizationId)->whereNull('archived_at')->whereNull('cancelled_at')->select(['id', 'title as label']);
    }
}
