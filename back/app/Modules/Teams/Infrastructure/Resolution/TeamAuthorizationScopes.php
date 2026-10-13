<?php

namespace App\Modules\Teams\Infrastructure\Resolution;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Validation\TeamMembershipInput;
use App\Modules\Teams\Domain\Contracts\TeamMembershipRepository;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\DatedAuthorizationScopeDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class TeamAuthorizationScopes implements DatedAuthorizationScopeDirectory
{
    public function __construct(private TeamMembershipRepository $records, private SportingPeopleDirectory $people, private ConnectionInterface $database) {}

    public function exists(string $organizationId, string $type, string $id): bool
    {
        return $type === 'team' && Team::query()->where('organization_id', $organizationId)->where('status', '!=', 'archived')->whereKey($id)->exists();
    }

    public function availableTypes(): array
    {
        return ['team'];
    }

    public function periodStart(string $org, string $type, string $id, int $userId, CarbonImmutable $at, ?CarbonImmutable $end = null, bool $cover = false): ?CarbonImmutable
    {
        if (! $this->exists($org, $type, $id) || ($person = $this->people->personForAccount($org, $userId)) === null) {
            return null;
        }
        foreach ($this->records->effectiveForAccount($org, $id, $person, $at) as $period) {
            if (! $cover || ($end === null ? $period->ends_at === null : ($period->ends_at === null || $period->ends_at->greaterThanOrEqualTo($end)))) {
                return $period->starts_at;
            }
        }

        return null;
    }

    public function bindAssignment(string $org, string $type, string $id, int $userId, string $assignmentId, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new \LogicException('Team grants bind in the assignment transaction.');
        }
        $person = $this->people->personForAccount($org, $userId);
        $existing = $this->database->table('team_scope_grants')->where('organization_id', $org)->where('role_assignment_id', $assignmentId)->first();
        if ($person !== null) {
            foreach ($this->records->effectiveForAccount($org, $id, $person, $start) as $period) {
                if ($end === null ? $period->ends_at === null : ($period->ends_at === null || $period->ends_at->greaterThanOrEqualTo($end))) {
                    if ($existing !== null && $existing->team_membership_id !== $period->id) {
                        continue;
                    }
                    if ($existing === null) {
                        if ($this->database->table('team_scope_grants')->where('organization_id', $org)->where('team_membership_id', $period->id)->count() >= TeamMembershipInput::MAX_SCOPE_GRANTS) {
                            throw new DomainConflictException(__('team_memberships.grant_limit'), 'TEAM_SCOPE_GRANT_LIMIT_REACHED');
                        }
                        $this->database->table('team_scope_grants')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'role_assignment_id' => $assignmentId, 'team_membership_id' => $period->id, 'created_at' => now()]);
                    }

                    return;
                }
            }
        }
        throw new DomainConflictException(__('team_memberships.grant_period'), 'ROLE_PERIOD_OUTSIDE_TEAM_MEMBERSHIP');
    }

    public function assignmentIds(string $org, string $type, string $id, int $userId, CarbonImmutable $at): array
    {
        if (! $this->exists($org, $type, $id) || ($person = $this->people->personForAccount($org, $userId)) === null) {
            return [];
        }
        $ids = array_map(fn (TeamMembership $period): string => $period->id, $this->records->effectiveForAccount($org, $id, $person, $at));

        $result = [];
        foreach ($this->database->table('team_scope_grants')->where('organization_id', $org)->whereIn('team_membership_id', $ids)->pluck('role_assignment_id') as $assignmentId) {
            if (! is_string($assignmentId)) {
                throw new \LogicException('Scope grant identifiers must be strings.');
            }
            $result[] = $assignmentId;
        }

        return $result;
    }
}
