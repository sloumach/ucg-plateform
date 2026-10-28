<?php

namespace App\Modules\Reporting\Application\Services;

use App\Modules\People\Application\Contracts\PersonSourceAudit;
use App\Modules\Performance\Application\Contracts\StatisticalSourceAudit;
use App\Modules\Reporting\Application\Data\DataPreparationReport;
use App\Modules\Reporting\Application\Validation\DataPreparationInput;
use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use Illuminate\Validation\ValidationException;

final readonly class DataPreparationService
{
    public function __construct(private DataSourceBundle $files, private DataPreparationInput $input, private PersonSourceAudit $people, private StatisticalSourceAudit $statistics) {}

    public function audit(string $path, string $organizationId): DataPreparationReport
    {
        $bundle = $this->files->manifest($path);
        $manifest = $this->input->parse($bundle['contents'], $organizationId);
        $issues = [];
        $counts = ['blocking' => 0, 'review' => 0];
        $byCode = [];
        $add = function (string $sourceId, string $code, string $severity = 'blocking', ?int $line = null, ?string $field = null,
            ?int $column = null, ?string $relatedSourceId = null, ?int $relatedLine = null) use (&$issues, &$counts, &$byCode): void {
            $counts[$severity]++;
            $byCode[$code] = ($byCode[$code] ?? 0) + 1;
            if (count($issues) < DataPreparationInput::MAX_ISSUES) {
                $issues[] = ['source_id' => $sourceId, 'code' => $code, 'severity' => $severity, 'line' => $line, 'field' => $field,
                    'column' => $column, 'related_source_id' => $relatedSourceId, 'related_line' => $relatedLine,
                    'message' => __('data_audit.issues.'.$code)];
            }
        };
        $inventory = [];
        $seenFiles = [];
        $seenKeys = [];
        $seenObservations = [];
        $secret = random_bytes(32);
        $totalBytes = 0;
        $totalRows = 0;
        $peopleRows = 0;
        $statisticsRows = 0;
        $readFiles = 0;
        $manifestHash = hash('sha256', $bundle['contents']);
        $digest = hash_init('sha256');
        hash_update($digest, $organizationId."\0".$manifestHash);
        foreach ($manifest['sources'] as $source) {
            $id = $source['id'];
            foreach (['owner_reference' => 'OWNER_UNRESOLVED', 'purpose_reference' => 'PURPOSE_UNRESOLVED', 'retention_reference' => 'RETENTION_UNRESOLVED'] as $key => $code) {
                if ($source[$key] === null) {
                    $add($id, $code);
                }
            }
            $entry = ['source_id' => $id, 'category' => $source['category'], 'bytes' => null, 'sha256' => null, 'status' => 'not_read',
                'rows' => 0, 'schema_valid_rows' => 0, 'mapped_columns' => [], 'ignored_columns' => array_column($source['ignored_columns'], 'column'),
                'owner_declared' => $source['owner_reference'] !== null, 'purpose_declared' => $source['purpose_reference'] !== null,
                'retention_declared' => $source['retention_reference'] !== null];
            $remainingBytes = DataPreparationInput::MAX_TOTAL_BYTES - $totalBytes;
            if ($remainingBytes <= 0 || (in_array($source['category'], ['people', 'statistics'], true) && $totalRows >= DataPreparationInput::MAX_ROWS)) {
                $add($id, 'BUNDLE_LIMIT');
                $entry['status'] = 'bundle_limit';
                $inventory[] = $entry;
                break;
            }
            try {
                $contents = $this->files->read($bundle['root'], $source['file'], min(DataPreparationInput::MAX_FILE_BYTES, $remainingBytes));
            } catch (ValidationException) {
                $add($id, 'FILE_UNAVAILABLE_OR_UNSAFE');
                $inventory[] = $entry;
                hash_update($digest, "\0".$id.':not_read');

                continue;
            }
            $totalBytes += strlen($contents);
            if ($totalBytes > DataPreparationInput::MAX_TOTAL_BYTES) {
                $add($id, 'BUNDLE_LIMIT');
                $entry['status'] = 'bundle_limit';
                $inventory[] = $entry;
                break;
            }
            $readFiles++;
            $fingerprint = hash('sha256', $contents);
            $entry['bytes'] = strlen($contents);
            $entry['sha256'] = $fingerprint;
            hash_update($digest, "\0".$id.':'.$fingerprint);
            if (isset($seenFiles[$fingerprint])) {
                $add($id, 'IDENTICAL_FILE_CONTENT', relatedSourceId: $seenFiles[$fingerprint]);
            } else {
                $seenFiles[$fingerprint] = $id;
            }
            if (! in_array($source['category'], ['people', 'statistics'], true) || strtolower(pathinfo($source['file'], PATHINFO_EXTENSION)) !== 'csv') {
                $entry['status'] = 'dedicated_audit_required';
                $add($id, 'FORMAT_REQUIRES_DEDICATED_AUDIT');
                $inventory[] = $entry;

                continue;
            }
            $isStatistics = $source['category'] === 'statistics';
            if ($isStatistics && ($source['mapping'] !== [] || $source['defaults'] !== [] || $source['ignored_columns'] !== [])) {
                $entry['status'] = 'statistics_settings_invalid';
                $add($id, 'MAPPING_INVALID');
                $inventory[] = $entry;

                continue;
            }
            $analysis = $isStatistics
                ? $this->statistics->analyze($contents, $source['delimiter'], $source['statistics_context']['as_of'] ?? null, DataPreparationInput::MAX_ROWS - $totalRows)
                : $this->people->analyze($contents, $source['delimiter'], $source['mapping'], $source['defaults'], $source['ignored_columns'], DataPreparationInput::MAX_ROWS - $totalRows);
            $entry['status'] = $isStatistics ? 'statistics_csv_audited' : 'people_csv_audited';
            $entry['rows'] = $analysis['row_count'];
            $totalRows += $analysis['row_count'];
            if ($isStatistics) {
                $statisticsRows += $analysis['row_count'];
            } else {
                $peopleRows += $analysis['row_count'];
            }
            foreach ($analysis['issues'] as $issue) {
                $add($id, $issue['code'], $issue['severity'], field: $issue['field'], column: $issue['column']);
            }
            if ($totalRows > DataPreparationInput::MAX_ROWS) {
                $entry['status'] = 'bundle_limit';
                $add($id, 'BUNDLE_LIMIT');
                $inventory[] = $entry;
                break;
            }
            if (! array_any($analysis['issues'], static fn (array $issue): bool => $issue['code'] === 'MAPPING_INVALID')) {
                // Only column numbers leave this service; source headers and arbitrary keys stay private.
                $entry['mapped_columns'] = array_values($source['mapping']);
            }
            foreach ($analysis['rows'] as $row) {
                if (! array_any($row['issues'], static fn (array $issue): bool => $issue['severity'] === 'blocking')) {
                    $entry['schema_valid_rows']++;
                }
                foreach ($row['issues'] as $issue) {
                    $add($id, $issue['code'], $issue['severity'], $row['line'], $issue['field'], $issue['column']);
                }
                if ($isStatistics) {
                    if (isset($row['keys']['statistical_identity'], $row['keys']['statistical_payload'])) {
                        $key = hash_hmac('sha256', $organizationId."\0statistics\0".$row['keys']['statistical_identity'], $secret);
                        $payload = hash_hmac('sha256', $row['keys']['statistical_payload'], $secret);
                        if (isset($seenObservations[$key])) {
                            $first = $seenObservations[$key];
                            $add($id, $first['payload'] === $payload ? 'DUPLICATE_OBSERVATION' : 'CONFLICTING_OBSERVATION',
                                line: $row['line'], relatedSourceId: $first['source_id'], relatedLine: $first['line']);
                        } else {
                            $seenObservations[$key] = ['source_id' => $id, 'line' => $row['line'], 'payload' => $payload];
                        }
                    }

                    continue;
                }
                foreach ($row['keys'] as $kind => $value) {
                    $key = hash_hmac('sha256', $organizationId."\0".$kind."\0".$value, $secret);
                    if (isset($seenKeys[$key])) {
                        $first = $seenKeys[$key];
                        $add($id, $kind === 'pseudonym' ? 'DUPLICATE_PSEUDONYM' : 'POSSIBLE_SHARED_IDENTITY',
                            $kind === 'pseudonym' ? 'blocking' : 'review', $row['line'],
                            ['pseudonym' => 'pseudonym', 'email' => 'contact_email', 'phone' => 'contact_phone', 'name' => 'identity'][$kind],
                            relatedSourceId: $first['source_id'], relatedLine: $first['line']);
                    } else {
                        $seenKeys[$key] = ['source_id' => $id, 'line' => $row['line']];
                    }
                }
            }
            $inventory[] = $entry;
        }
        if (count($manifest['sources']) > count($inventory)) {
            foreach (array_slice($manifest['sources'], count($inventory)) as $source) {
                $inventory[] = ['source_id' => $source['id'], 'category' => $source['category'], 'status' => 'not_processed_due_to_limit'];
            }
        }
        $unprocessed = count(array_filter($inventory, static fn (array $entry): bool => in_array($entry['status'], ['not_read', 'bundle_limit', 'not_processed_due_to_limit'], true)));
        ksort($byCode);

        return new DataPreparationReport([
            'version' => 1, 'objective' => 'UCG-DATA-001', 'mode' => 'read_only', 'organization_id' => $organizationId,
            'dataset_kind_declared' => $manifest['dataset_kind'], 'inventory_scope' => 'explicit_manifest_only',
            'status' => $counts['blocking'] > 0 ? 'blocked' : 'ready_for_business_review', 'import_authorized' => false,
            'manifest_sha256' => $manifestHash, 'plan_digest' => hash_final($digest),
            'summary' => ['declared_files' => count($manifest['sources']), 'read_files' => $readFiles, 'unprocessed_files' => $unprocessed,
                'bytes' => $totalBytes, 'people_rows' => $peopleRows, 'statistics_rows' => $statisticsRows, 'blocking_issues' => $counts['blocking'], 'review_issues' => $counts['review']],
            'sources' => $inventory, 'issue_counts' => $byCode, 'issues' => $issues,
            'issues_truncated' => array_sum($counts) > count($issues),
            'unrepresented_categories' => array_values(array_diff(DataPreparationInput::CATEGORIES, array_column($manifest['sources'], 'category'))),
            'not_verified' => ['file_ownership_and_purpose', 'retention_approval', 'source_completeness', 'application_tenant_and_duplicates',
                'application_quotas', 'unsupported_formats', 'statistical_references_and_metric_rules', 'statistical_cutoff_timezone', 'cross_module_links', 'business_signoff'],
            'automatic_merge' => false, 'database_checked' => false, 'files_modified' => false,
        ]);
    }
}
