<?php

namespace App\Support\Operations;

use App\Contracts\Operations\BackgroundWorkloads;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class BackgroundWorkloadRepository implements BackgroundWorkloads
{
    public function __construct(private DatabaseManager $database, private ReminderCoverageRepository $coverage) {}

    public function inspect(string $source, BackgroundSettings $settings): RuntimeCheck
    {
        if (in_array($source, ['contract_sweeps', 'competition_sweeps'], true)) {
            return $this->coverage->inspect($source, $settings);
        }
        [$table, $column, $active, $failures] = match ($source) {
            'person_imports' => ['person_imports', 'status', ['queued', 'processing'], ['failed']],
            'statistical_imports' => ['statistical_import_executions', 'status', ['queued'], ['failed']],
            'contract_reminders' => ['contract_reminder_deliveries', 'email_status', ['pending', 'sending'], ['uncertain']],
            default => throw new InvalidArgumentException('Unknown background source.'),
        };
        $rows = $this->database->connection()->table($table)->whereIn($column, [...$active, ...$failures])
            ->orderBy('updated_at')->orderBy('id')->limit($settings->sampleLimit + 1)
            ->get([$column, 'updated_at']);
        $truncated = $rows->count() > $settings->sampleLimit;
        $pending = 0;
        $late = 0;
        $failed = 0;
        $oldest = 0;
        $invalid = false;
        $now = now()->getTimestamp();
        foreach ($rows->take($settings->sampleLimit) as $row) {
            if (in_array($row->{$column}, $failures, true)) {
                $failed++;

                continue;
            }
            $pending++;
            if (! is_string($row->updated_at) || $row->updated_at === '') {
                $invalid = true;

                continue;
            }
            $timestamp = CarbonImmutable::parse($row->updated_at, 'UTC')->getTimestamp();
            if ($timestamp > $now) {
                $invalid = true;

                continue;
            }
            $age = $now - $timestamp;
            $oldest = max($oldest, $age);
            if ($age >= $settings->workWarningSeconds) {
                $late++;
            }
        }
        $warning = $late > 0 || $failed > 0 || $invalid;

        return new RuntimeCheck($warning ? ProbeState::Warning : ($truncated ? ProbeState::Unverified : ProbeState::Passed),
            $warning ? 'BACKGROUND_WORK_REVIEW' : ($truncated ? 'BACKGROUND_WORK_SAMPLE_TRUNCATED' : 'BACKGROUND_WORK_METADATA_PASSED'),
            ['sampled' => min($rows->count(), $settings->sampleLimit), 'active_observed' => $pending,
                'late_observed' => $late, 'failed_or_uncertain_observed' => $failed,
                'oldest_active_update_age_seconds_observed' => $oldest, 'invalid_time_observed' => $invalid,
                'truncated' => $truncated, 'sample_limit' => $settings->sampleLimit,
                'warning_seconds' => $settings->workWarningSeconds, 'scope' => 'global_metadata_sample',
                'current_outage_proven' => false]);
    }
}
