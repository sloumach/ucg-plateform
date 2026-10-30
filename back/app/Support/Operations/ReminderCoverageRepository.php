<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class ReminderCoverageRepository
{
    public function __construct(private DatabaseManager $database, private Repository $config, private MonitoringScope $scope) {}

    public function inspect(string $source, BackgroundSettings $settings): RuntimeCheck
    {
        $warningSeconds = $this->scope->integer('monitoring.reminder_sweep_warning_seconds', 900, 86400);
        if ($source === 'contract_sweeps') {
            $query = $this->database->connection()->table('contract_reminder_plans as p')
                ->join('contracts as c', static function ($join): void {
                    $join->on('c.id', '=', 'p.contract_id')->on('c.organization_id', '=', 'p.organization_id');
                })->where('p.enabled', true)->whereIn('c.status', ['approved', 'sent', 'signed', 'active']);
            $task = 'contract-reminders';
        } elseif ($source === 'competition_sweeps') {
            $query = $this->database->connection()->table('competition_registration_revisions as p')
                ->join('external_competitions as c', static function ($join): void {
                    $join->on('c.id', '=', 'p.competition_id')->on('c.organization_id', '=', 'p.organization_id');
                })->where('c.status', 'active')->where('p.next_due_at', '<=', now()->addDays(7))
                ->whereNotExists(static fn (Builder $newer): Builder => $newer->selectRaw('1')
                    ->from('competition_registration_revisions as n')->whereColumn('n.organization_id', 'p.organization_id')
                    ->whereColumn('n.competition_id', 'p.competition_id')->whereColumn('n.number', '>', 'p.number'));
            $task = 'competition-registration-reminders';
        } else {
            throw new InvalidArgumentException('REMINDER_SOURCE_INVALID');
        }
        $query->join('organizations as o', 'o.id', '=', 'p.organization_id')->where('o.status', 'active');
        $rows = $query->orderByRaw('CASE WHEN p.checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('p.checked_at')->orderBy('p.id')->limit($settings->sampleLimit + 1)->get(['p.checked_at', 'p.created_at']);
        $late = 0;
        $unchecked = 0;
        $invalid = false;
        foreach ($rows->take($settings->sampleLimit) as $row) {
            $unchecked += $row->checked_at === null ? 1 : 0;
            $value = $row->checked_at ?? $row->created_at;
            if (! is_string($value) || $value === '') {
                $invalid = true;

                continue;
            }
            $age = now()->getTimestamp() - CarbonImmutable::parse($value, 'UTC')->getTimestamp();
            $invalid = $invalid || $age < 0;
            $late += $age >= $warningSeconds ? 1 : 0;
        }
        $tasks = $this->config->get('tenancy.scheduled_tasks', []);
        $enabled = is_array($tasks) && array_key_exists($task, $tasks);
        $warning = $late > 0 || $invalid || (! $enabled && $rows->isNotEmpty());
        $truncated = $rows->count() > $settings->sampleLimit;

        return new RuntimeCheck($warning ? ProbeState::Warning : ($truncated ? ProbeState::Unverified : ProbeState::Passed),
            $warning ? 'REMINDER_SWEEP_REVIEW' : ($truncated ? 'REMINDER_SWEEP_TRUNCATED' : 'REMINDER_SWEEP_METADATA_PASSED'),
            ['sampled' => min($settings->sampleLimit, $rows->count()), 'late_observed' => $late,
                'never_checked_observed' => $unchecked, 'invalid_time_observed' => $invalid,
                'schedule_registered' => $enabled, 'truncated' => $truncated,
                'warning_seconds' => $warningSeconds,
                'materialization_and_delivery_verified' => false, 'scope' => 'eligible_sweep_metadata']);
    }
}
