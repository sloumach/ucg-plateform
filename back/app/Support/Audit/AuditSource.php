<?php

namespace App\Support\Audit;

use Carbon\CarbonImmutable;
use stdClass;

final readonly class AuditSource
{
    /**
     * @param  list<string>  $columns
     * @param  array<string,string>  $beforeMap
     * @param  array<string,string>  $afterMap
     * @param  list<string>|null  $knownActorActions
     */
    public function __construct(public string $name, public string $table, public array $columns,
        public string $actorColumn, public string $targetColumn, public AuditTarget $targetType,
        public AuditReason $reason, public bool $tenantOwned = false, public ?string $targetTypeColumn = null,
        public array $beforeMap = [], public array $afterMap = [], public bool $jsonStates = false,
        public ?array $knownActorActions = null, public ?string $fixedAction = null, public bool $targetFromAction = false) {}

    public function project(stdClass $row): AuditEntry
    {
        $actor = $row->{$this->actorColumn};
        if ($this->knownActorActions !== null && ! in_array($row->action, $this->knownActorActions, true)) {
            $actor = null;
        }
        $before = $this->state($row, 'before', $this->beforeMap);
        $after = $this->state($row, 'after', $this->afterMap);
        $target = $row->{$this->targetColumn};
        $targetType = $this->targetTypeColumn === null ? $this->targetType->value : (string) $row->{$this->targetTypeColumn};
        if ($this->targetFromAction) {
            $prefix = explode('.', (string) $row->action)[0];
            $targetType = (AuditTarget::tryFrom($prefix) ?? $this->targetType)->value;
        }

        return new AuditEntry((string) $row->id, $this->name, $this->tenantOwned ? (string) $row->organization_id : null,
            $actor === null ? null : (int) $actor, $this->fixedAction ?? (string) $row->action,
            $targetType,
            $target === null ? null : (string) $target, $before, $after, (string) ($row->reason_code ?? $this->reason->value),
            (string) $row->request_id, isset($row->ip_address) ? (string) $row->ip_address : null,
            CarbonImmutable::parse($row->occurred_at)->utc()->format('Y-m-d\TH:i:s.u\Z'), (string) $row->occurred_at);
    }

    /**
     * @param  array<string,string>  $map
     * @return array<string, bool|int|string|list<string>|null>
     */
    private function state(stdClass $row, string $name, array $map): array
    {
        $state = [];
        if ($this->jsonStates && is_string($row->{$name} ?? null)) {
            $decoded = json_decode($row->{$name}, true, 32, JSON_THROW_ON_ERROR);
            $state = is_array($decoded) ? $decoded : [];
        }
        foreach ($map as $key => $column) {
            $value = $row->{$column} ?? null;
            if ($value !== null && $key === 'active') {
                $value = (bool) $value;
            }
            $state[$key] = $value;
        }

        return AuditState::project($state);
    }
}
