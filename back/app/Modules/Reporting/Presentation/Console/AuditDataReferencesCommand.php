<?php

namespace App\Modules\Reporting\Presentation\Console;

use App\Modules\Reporting\Application\Services\DataReferenceAuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

final class AuditDataReferencesCommand extends Command
{
    protected $signature = 'data:references {manifest} {checks : Contrôles JSON relatifs au manifeste} {--organization=} {--actor=}';

    protected $description = 'DATA-001 : vérifier des références explicites en base, en lecture seule.';

    public function handle(DataReferenceAuditService $service, LoggerInterface $logger): int
    {
        try {
            $path = $this->argument('manifest');
            $organization = $this->option('organization');
            if (! is_string($organization)) {
                throw ValidationException::withMessages(['organization' => __('data_audit.invalid_review')]);
            }
            $actorValue = $this->option('actor');
            $actor = filter_var($actorValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_string($actorValue) || ! ctype_digit($actorValue) || $actor === false) {
                throw ValidationException::withMessages(['actor' => __('data_audit.invalid_review')]);
            }
            $report = $service->audit($path, $organization, $this->argument('checks'), $actor);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['status'] === 'requested_checks_passed' ? self::SUCCESS : 2;
        } catch (ValidationException|AuthorizationException|ModelNotFoundException) {
            $this->error(__('data_audit.invalid_review'));
        } catch (Throwable $exception) {
            $logger->error('data.references.failed', ['exception_type' => $exception::class]);
            $this->error(__('data_audit.failed'));
        }

        return self::FAILURE;
    }
}
