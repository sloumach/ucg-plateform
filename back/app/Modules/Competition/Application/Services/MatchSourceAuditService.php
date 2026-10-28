<?php

namespace App\Modules\Competition\Application\Services;

use App\Modules\Competition\Application\Contracts\MatchSourceAudit;
use App\Modules\Competition\Application\Validation\MatchInput;
use App\Modules\Scheduling\Application\Contracts\MatchSchedule;
use App\Support\Files\JsonDatasetAuditor;
use Illuminate\Validation\ValidationException;

final readonly class MatchSourceAuditService implements MatchSourceAudit
{
    public function __construct(private JsonDatasetAuditor $auditor, private MatchSchedule $schedule) {}

    public function analyze(string $contents, string $organization, int $maximumRows = 100): array
    {
        return $this->auditor->analyze($contents, $organization, MatchInput::rules('store'), function (array $values): array {
            $values = $this->auditor->canonical($values);
            $period = $this->schedule->period($values['starts_at'], $values['ends_at'], $values['origin_timezone']);
            $values['starts_at'] = $period['starts_at']->utc()->toIso8601String();
            $values['ends_at'] = $period['ends_at']->utc()->toIso8601String();
            $participants = array_map(static fn (array $item): string => $item['kind'].':'.$item['source_id'], $values['participants']);
            if (count(array_unique($participants)) !== count($participants)) {
                throw ValidationException::withMessages(['participants' => __('matches.choice')]);
            }
            sort($participants);
            $values += ['visibility' => 'private'];
            unset($values['confirm_sharing']);

            return ['identity' => [$values['game_id'], $values['starts_at'], $participants], 'payload' => $values];
        }, $maximumRows);
    }
}
