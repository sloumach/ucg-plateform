<?php

namespace App\Modules\Reporting\Presentation\Console;

use App\Modules\Reporting\Application\Services\DataPreparationReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ReviewDataPreparationCommand extends Command
{
    protected $signature = 'data:review {manifest} {review : Revue JSON relative au manifeste} {--organization=}';

    protected $description = 'DATA-001 : vérifier la revue déclarée contre les fichiers actuels, sans import.';

    public function handle(DataPreparationReviewService $service, LoggerInterface $logger): int
    {
        try {
            $path = $this->argument('manifest');
            $organization = $this->option('organization');
            if (! is_string($organization)) {
                throw ValidationException::withMessages(['organization' => __('data_audit.invalid_review')]);
            }

            $report = $service->review($path, $organization, $this->argument('review'));
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['status'] === 'business_approval_declared' ? self::SUCCESS : 2;
        } catch (ValidationException|AuthorizationException|ModelNotFoundException) {
            $this->error(__('data_audit.invalid_review'));
        } catch (Throwable $exception) {
            $logger->error('data.review.failed', ['exception_type' => $exception::class]);
            $this->error(__('data_audit.failed'));
        }

        return self::FAILURE;
    }
}
