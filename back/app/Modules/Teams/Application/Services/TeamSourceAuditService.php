<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Application\Contracts\TeamSourceAudit;
use App\Modules\Teams\Application\Validation\TeamInput;
use App\Support\Files\JsonDatasetAuditor;
use Illuminate\Validation\ValidationException;

final readonly class TeamSourceAuditService implements TeamSourceAudit
{
    public function __construct(private JsonDatasetAuditor $auditor) {}

    public function analyze(string $contents, string $organization, int $maximumRows = 100): array
    {
        return $this->auditor->analyze($contents, $organization, TeamInput::saveRules(true), function (array $values): array {
            $values = $this->auditor->canonical($values);
            $category = $values['custom_category'] ?? null;
            if (($values['kind'] === 'custom') !== ($category !== null)) {
                throw ValidationException::withMessages(['custom_category' => __('teams.category')]);
            }
            $values += ['status' => 'draft', 'custom_category' => null, 'short_name' => null, 'public_description' => null, 'color' => null];

            return ['identity' => [$values['game_id'], $values['slug'], $values['season']], 'payload' => $values];
        }, $maximumRows);
    }
}
