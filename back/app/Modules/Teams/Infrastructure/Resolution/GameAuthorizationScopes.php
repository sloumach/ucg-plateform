<?php

namespace App\Modules\Teams\Infrastructure\Resolution;

use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeSource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class GameAuthorizationScopes implements AuthorizationScopeSource
{
    public function __construct(private ConnectionInterface $database) {}

    public function eligibleAssignmentIds(string $org, string $type, int $userId): ?Builder
    {
        return null;
    }

    public function exists(string $organizationId, string $type, string $id): bool
    {
        return $type === 'game' && $this->sources($organizationId, $type)->where('source.id', $id)->exists();
    }

    public function availableTypes(): array
    {
        return ['game'];
    }

    public function choices(TenantContext $context, string $type, int $page, int $perPage, ?Builder $visible = null): LengthAwarePaginator
    {
        return $this->sources($context->organizationId, $type)->when($visible !== null, fn ($query) => $query->whereIn('source.id', $visible))->orderBy('label')->orderBy('source.id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function sources(string $organizationId, string $type): Builder
    {
        if ($type !== 'game') {
            throw new ModelNotFoundException;
        }

        return $this->database->table('game_catalogue_entries as source')->where('source.kind', 'game')->where('source.active', true)
            ->leftJoin('organization_games as setting', fn ($join) => $join->on('setting.game_id', '=', 'source.id')->where('setting.organization_id', $organizationId))
            ->where(fn ($query) => $query->whereNotNull('setting.id')->orWhere(fn ($default) => $default->whereNull('setting.id')->where('source.default_enabled', true)))
            ->select(['source.id', 'source.name as label']);
    }
}
