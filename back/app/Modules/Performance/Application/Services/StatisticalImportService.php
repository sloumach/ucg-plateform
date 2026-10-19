<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\ObservationMatchDirectory;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Performance\Application\Validation\ObservationInput;
use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalImportRepository;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Teams\Application\Contracts\MetricGameDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantResourceLimits;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use App\Support\Files\CsvParser;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StatisticalImportService
{
    public const MAX_BYTES = 1048576;

    public const MAX_ROWS = 100;

    public function __construct(private StatisticalImportRepository $imports, private ObservationService $observations,
        private CsvParser $parser, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private MetricGameDirectory $catalogue, private MetricCatalogueRepository $metrics,
        private TenantArtifacts $artifacts, private TenantResourceLimits $limits, private Encrypter $crypt,
        private ConnectionInterface $database, private TenantAuditJournal $audit,
        private ObservationMatchDirectory $matches, private SportingPeopleDirectory $people) {}

    public function authorize(TenantContext $context): void
    {
        $this->observations->authorize($context, 'create');
        $this->authorization->authorize($context, 'sports.statistics.import');
    }

    public function upload(TenantContext $context, string $contents, string $delimiter): StatisticalImport
    {
        $this->authorize($context);
        if (strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => __('statistical_imports.csv')]);
        }
        $this->limits->assertImport($context, strlen($contents), 0);
        $parsed = $this->parser->parse($contents, $delimiter, min(self::MAX_ROWS, $this->limits->value($context, 'import_rows')));
        $rules = ObservationInput::rules('store');
        $required = array_keys(array_filter($rules, fn (array $rules): bool => in_array('required', $rules, true)));
        if (array_diff($parsed['headers'], array_keys($rules)) !== [] || array_diff($required, $parsed['headers']) !== []) {
            throw ValidationException::withMessages(['file' => __('statistical_imports.schema')]);
        }
        $this->limits->assertImport($context, strlen($contents), count($parsed['rows']));
        // An upload retry reuses only this actor's preview in this tenant, never another actor's data.
        $fingerprint = hash_hmac('sha256', $context->organizationId.':'.$context->actorUserId.':'.$delimiter.$contents, $this->crypt->getKey());
        $existing = $this->imports->fingerprint($context->organizationId, $context->actorUserId, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }
        $artifact = $this->artifacts->store($context, 'statistical-preview.csv.enc', $this->crypt->encryptString($contents));
        try {
            $run = $this->database->transaction(function () use ($context, $parsed, $rules, $artifact, $fingerprint, $delimiter): StatisticalImport {
                $this->guard->lock($context);
                $this->authorize($context);
                $existing = $this->imports->fingerprint($context->organizationId, $context->actorUserId, $fingerprint);
                if ($existing !== null) {
                    return $existing;
                }
                $this->catalogue->lock($this->catalogue->version());
                $this->metrics->state(true);
                $rows = [];
                $seen = [];
                foreach ($parsed['rows'] as $record) {
                    $values = array_fill_keys(array_keys($rules), null);
                    foreach ($parsed['headers'] as $column => $field) {
                        $cell = trim($record['cells'][$column] ?? '');
                        $values[$field] = $cell === '' && in_array('nullable', $rules[$field], true) ? null : $cell;
                    }
                    $errors = [];
                    $resolved = null;
                    if (count($record['cells']) !== count($parsed['headers'])) {
                        $errors['file'] = [__('statistical_imports.columns')];
                    } else {
                        try {
                            // The same domain rules as manual entry, with no persistence call.
                            $draft = $this->observations->draft($context, $values);
                            $root = $draft['observation'];
                            $values = [...$values, ...$draft['revision']];
                            $resolved = ['person_id' => $root['person_id'], 'metric_id' => $root['metric_id'],
                                'metric_version' => $root['metric_revision_id'], 'game_id' => $root['game_id'],
                                'match_id' => $root['match_id'], 'match_game_id' => $root['match_game_id'],
                                'metric' => $root['context']['metric'], 'game_context' => $root['context']['game_context']];
                        } catch (ValidationException $exception) {
                            $errors = $exception->errors();
                        } catch (ModelNotFoundException) {
                            $errors['reference'] = [__('statistical_imports.reference')];
                        } catch (DomainConflictException $exception) {
                            $errors['reference'] = [__($exception->errorCode() === 'OBSERVATION_METRIC' ? 'statistical_imports.stale' : 'statistical_imports.reference')];
                        }
                    }
                    $key = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
                    if ($errors === [] && isset($seen[$key])) {
                        $errors['duplicate'] = [__('statistical_imports.duplicate')];
                    }
                    if ($errors === []) {
                        $seen[$key] = true;
                    }
                    $rows[] = ['line' => $record['line'], 'status' => $errors === [] ? 'valid' : 'invalid',
                        'values' => $values, 'errors' => $errors, 'resolved' => $errors === [] ? $resolved : null];
                }
                $valid = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'valid'));
                $run = $this->imports->create(['organization_id' => $context->organizationId, 'actor_user_id' => $context->actorUserId,
                    'artifact_id' => $artifact->id, 'fingerprint' => $fingerprint, 'delimiter' => $delimiter,
                    'headers' => $parsed['headers'], 'preview' => $rows, 'row_count' => count($rows), 'valid_count' => $valid,
                    'invalid_count' => count($rows) - $valid, 'request_id' => $context->requestId]);
                $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                    AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, null, [],
                    ['statistical_import_id' => $run->id]));

                return $run;
            });
        } catch (Throwable $exception) {
            $this->cleanup($context, $artifact->id);
            throw $exception;
        }
        if ($run->artifact_id !== $artifact->id) {
            $this->cleanup($context, $artifact->id);
        }

        return $run;
    }

    /** @return LengthAwarePaginator<int,array<string,mixed>> */
    public function index(TenantContext $context, int $page): LengthAwarePaginator
    {
        $this->authorize($context);

        return $this->imports->paginate($context->organizationId, $context->actorUserId, $page)
            ->through(fn (StatisticalImport $run): array => $this->summary($run));
    }

    /** @return array<string,mixed> */
    public function show(TenantContext $context, string $id, int $page = 1): array
    {
        $this->authorize($context);
        $run = $this->imports->find($context->organizationId, $context->actorUserId, $id);
        $rows = $run->preview;
        $checked = [];
        // Even the original author loses the snapshot when a linked match becomes inaccessible.
        foreach ($rows as $row) {
            $resolved = $row['resolved'];
            if ($resolved !== null && $resolved['match_id'] !== null && ! isset($checked[$resolved['match_id']])) {
                $this->matches->assertReference($context, $resolved['match_id'], $resolved['game_id'], null);
                $checked[$resolved['match_id']] = true;
            }
        }
        $visible = array_slice($rows, ($page - 1) * 10, 10);
        $ids = array_values(array_unique(array_filter(array_column(array_column($visible, 'resolved'), 'person_id'))));
        $labels = $this->people->pseudonyms($context, $ids);
        foreach ($visible as &$row) {
            if ($row['resolved'] !== null) {
                $row['resolved']['person_label'] = $labels[$row['resolved']['person_id']] ?? null;
            }
        }
        unset($row);

        return [...$this->summary($run), 'headers' => $run->headers, 'delimiter' => $run->delimiter, 'rows' => [
            'data' => $visible, 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($run->row_count / 10)), 'per_page' => 10, 'total' => $run->row_count]]];
    }

    /** @return array<string,mixed> */
    private function summary(StatisticalImport $run): array
    {
        return ['id' => $run->id, 'status' => 'previewed', 'row_count' => $run->row_count,
            'valid_count' => $run->valid_count, 'invalid_count' => $run->invalid_count, 'created_at' => $run->created_at->toISOString(),
            'can_confirm' => false];
    }

    private function cleanup(TenantContext $context, string $artifact): void
    {
        try {
            $this->artifacts->delete($context, $artifact);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
