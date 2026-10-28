<?php

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Competition\Application\Contracts\DataPreparationReferences as MatchReferences;
use App\Modules\Contracts\Application\Contracts\DataPreparationReferences as ContractReferences;
use App\Modules\People\Application\Contracts\DataPreparationReferences as PeopleReferences;
use App\Modules\Performance\Application\Contracts\DataPreparationReferences as MetricReferences;
use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use App\Modules\Teams\Application\Contracts\DataPreparationReferences as TeamReferences;
use App\Modules\Tenancy\Application\Contracts\DataPreparationContext;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class DataReferenceAuditService
{
    private const array KINDS = ['person_id', 'person_pseudonym', 'team_id', 'team_slug', 'match_id', 'contract_id', 'contract_reference', 'metric_id'];

    public function __construct(private DataPreparationService $audit, private DataSourceBundle $files, private Factory $validator,
        private DataPreparationContext $contexts, private PeopleReferences $people, private TeamReferences $teams,
        private MatchReferences $matches, private ContractReferences $contracts, private MetricReferences $metrics) {}

    /** @return array<string,mixed> */
    public function audit(string $manifest, string $organization, string $checksFile, int $actor): array
    {
        // Validate files and scope before opening any application connection.
        $report = $this->audit->audit($manifest, $organization)->data;
        if ($report['status'] === 'blocked') {
            throw ValidationException::withMessages(['checks' => __('data_audit.invalid_review')]);
        }
        $bundle = $this->files->manifest($manifest);
        try {
            $checks = json_decode($this->files->read($bundle['root'], $checksFile, 65536), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['checks' => __('data_audit.invalid_review')]);
        }
        $data = $this->validator->make(['checks' => $checks, 'organization' => $organization, 'digest' => $report['plan_digest']], [
            'checks' => ['required', 'array:version,organization_id,plan_digest,items'],
            'checks.version' => ['required', 'integer', 'in:1'],
            'checks.organization_id' => ['required', 'uuid', 'same:organization'],
            'checks.plan_digest' => ['required', 'string', 'same:digest'],
            'checks.items' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'checks.items.*' => ['required', 'array:source_id,line,kind,value,expect'],
            'checks.items.*.source_id' => ['required', Rule::in(array_column($report['sources'], 'source_id'))],
            'checks.items.*.line' => ['required', 'integer', 'min:1', 'max:1000'],
            'checks.items.*.kind' => ['required', Rule::in(self::KINDS)],
            'checks.items.*.value' => ['required', 'string', 'max:160'],
            'checks.items.*.expect' => ['required', Rule::in(['present', 'absent'])],
        ])->validate()['checks'];
        $counts = array_column($report['sources'], 'rows', 'source_id');
        foreach ($data['items'] as $check) {
            if ($check['line'] > ($counts[$check['source_id']] ?? 0)
                || (str_ends_with($check['kind'], '_id') && ! Str::isUuid($check['value']))) {
                throw ValidationException::withMessages(['checks' => __('data_audit.invalid_review')]);
            }
        }
        $context = $this->contexts->resolve($organization, $actor);
        $groups = ['person' => [], 'team' => [], 'match' => [], 'contract' => [], 'metric' => []];
        foreach ($data['items'] as $position => $check) {
            $group = explode('_', $check['kind'])[0];
            $groups[$group][$position] = ['kind' => $check['kind'], 'value' => str_ends_with($check['kind'], '_id') ? strtolower($check['value']) : trim($check['value'])];
        }
        $found = [];
        foreach (['person' => $this->people, 'team' => $this->teams, 'match' => $this->matches, 'contract' => $this->contracts, 'metric' => $this->metrics] as $group => $directory) {
            if ($groups[$group] !== []) {
                $found += $directory->lookup($context, $groups[$group]);
            }
        }
        $issues = [];
        foreach ($data['items'] as $position => $check) {
            if (! array_key_exists($position, $found) || $found[$position] !== ($check['expect'] === 'present')) {
                $issues[] = ['source_id' => $check['source_id'], 'line' => $check['line'], 'code' => 'REFERENCE_CHECK_FAILED'];
            }
        }

        return ['version' => 1, 'objective' => 'UCG-DATA-001', 'mode' => 'read_only', 'organization_id' => $organization,
            'plan_digest' => $report['plan_digest'], 'status' => $issues === [] ? 'requested_checks_passed' : 'blocked',
            'checked_at' => now()->toIso8601String(), 'checks_sha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
            'checked_count' => count($data['items']), 'issues' => $issues, 'database_checked' => true,
            'check_scope' => 'explicit_requests_only', 'coverage_verified' => false, 'import_authorized' => false,
            'not_verified' => ['check_list_completeness', 'metric_version_units_and_aggregation', 'reference_lifecycle_and_game_compatibility',
                'shared_identity_duplicates', 'match_identity_duplicates', 'tenant_quotas', 'business_signoff']];
    }
}
