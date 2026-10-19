<?php

namespace App\Modules\Reporting\Domain;

use Illuminate\Database\Eloquent\ModelNotFoundException;

final class DashboardCatalogue
{
    public const VIEWS = ['direction', 'manager', 'coach', 'player', 'administration'];

    /** @return array<string,array{source:string,views:list<string>,permissions:list<string>,period:string,scope:string,reason:?string}> */
    public function cards(): array
    {
        return [
            'active_people' => ['source' => 'people', 'views' => ['direction'], 'permissions' => ['sports.read'], 'period' => 'snapshot', 'scope' => 'organization', 'reason' => null],
            'expiring_contracts' => ['source' => 'contracts', 'views' => ['direction', 'administration'], 'permissions' => ['administrative.read'], 'period' => 'window', 'scope' => 'organization', 'reason' => null],
            'overdue_deliverables' => ['source' => 'partnerships', 'views' => ['direction', 'manager'], 'permissions' => ['partnerships.read'], 'period' => 'snapshot', 'scope' => 'organization', 'reason' => null],
            'activities' => ['source' => 'scheduling', 'views' => ['manager', 'coach'], 'permissions' => ['calendar.read'], 'period' => 'window', 'scope' => 'current_audience', 'reason' => null],
            'matches' => ['source' => 'competition', 'views' => ['direction', 'manager', 'coach'], 'permissions' => ['sports.read'], 'period' => 'window', 'scope' => 'current_audience', 'reason' => null],
            'registration_due' => ['source' => 'competition', 'views' => ['manager'], 'permissions' => ['sports.read', 'sports.competitions.read', 'sports.registrations.read'], 'period' => 'window', 'scope' => 'organization', 'reason' => null],
            'invitations' => ['source' => 'scheduling', 'views' => ['player'], 'permissions' => ['calendar.read'], 'period' => 'snapshot', 'scope' => 'self', 'reason' => null],
            'availability' => ['source' => 'scheduling', 'views' => ['player'], 'permissions' => ['availability.self'], 'period' => 'window', 'scope' => 'self', 'reason' => null],
            'calculations' => ['source' => 'performance', 'views' => ['coach'], 'permissions' => ['sports.read', 'sports.statistics.read'], 'period' => 'snapshot', 'scope' => 'self', 'reason' => null],
            'unlinked_people' => ['source' => 'people', 'views' => ['administration'], 'permissions' => ['administrative.read'], 'period' => 'snapshot', 'scope' => 'organization', 'reason' => null],
            'people_imports' => ['source' => 'people', 'views' => ['administration'], 'permissions' => ['sports.read', 'sports.write', 'administrative.read', 'administrative.write'], 'period' => 'snapshot', 'scope' => 'organization', 'reason' => null],
            'budgets' => ['source' => 'none', 'views' => ['direction'], 'permissions' => ['financial.read'], 'period' => 'snapshot', 'scope' => 'organization', 'reason' => 'FINANCE_REQUIRED'],
            'team_progress' => ['source' => 'none', 'views' => ['coach'], 'permissions' => ['sports.read', 'sports.statistics.read'], 'period' => 'window', 'scope' => 'current_audience', 'reason' => 'REP_002_REQUIRED'],
        ];
    }

    /** @return array{source:string,views:list<string>,permissions:list<string>,period:string,scope:string,reason:?string} */
    public function find(string $id): array
    {
        return $this->cards()[$id] ?? throw new ModelNotFoundException;
    }
}
