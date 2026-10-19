<?php

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Services\CompetitionOutcomeService;
use App\Modules\Competition\Application\Services\CompetitionRegistrationService;
use App\Modules\Competition\Application\Services\ExternalCompetitionDocumentService;
use App\Modules\Competition\Application\Services\ExternalCompetitionService;
use App\Modules\Competition\Application\Services\MatchCorrectionService;
use App\Modules\Competition\Application\Services\MatchLineupService;
use App\Modules\Competition\Application\Services\MatchNotebookService;
use App\Modules\Competition\Application\Services\MatchResultService;
use App\Modules\Competition\Application\Services\MatchScoreService;
use App\Modules\Competition\Application\Services\MatchService;
use App\Modules\Competition\Application\Services\ProcessCompetitionRegistrationReminders;
use App\Modules\Performance\Application\Services\MetricCatalogueService;
use App\Modules\Performance\Application\Services\ObservationService;
use App\Modules\Performance\Application\Services\StatisticalImportProcessor;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! preg_match('/^ucg_match001_race_[a-f0-9]{12}$/D', config('database.connections.pgsql.database'))) {
    fwrite(STDERR, 'Isolated concurrency database required.');
    exit(2);
}
$payload = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
$barrier = $argv[2] ?? '';
if (! preg_match('/^ucg_match001_go_[a-f0-9]{24}$/D', basename($barrier))) {
    throw new UnexpectedValueException('Isolated test barrier required.');
}
Carbon\Carbon::setTestNow($payload['now']);
CarbonImmutable::setTestNow($payload['now']);
$context = new TenantContext($payload['org'], $payload['actor'], 'concurrency', 'UTC', 'fr', (string) Str::uuid());
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$deadline = microtime(true) + 25;
while (! is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Test barrier was not released.');
    }
    usleep(10000);
    clearstatcache(true, $barrier);
}
try {
    $service = app(MatchService::class);
    if ($payload['operation'] === 'statistical_import') {
        app(StatisticalImportProcessor::class)->process($context, $payload['import'], $payload['version']);
    } elseif ($payload['operation'] === 'observation') {
        app(ObservationService::class)->save($context, $payload['input'], $payload['observation']);
    } elseif ($payload['operation'] === 'metric') {
        app(MetricCatalogueService::class)->save($context->actorUserId, $context->requestId, $payload['input'], $payload['metric']);
    } elseif ($payload['operation'] === 'competition_outcome') {
        app(CompetitionOutcomeService::class)->save($context, $payload['competition'], $payload['stream'], $payload['input'], null);
    } elseif ($payload['operation'] === 'registration') {
        app(CompetitionRegistrationService::class)->save($context, $payload['competition'], $payload['input'], null);
    } elseif ($payload['operation'] === 'registration_reminders') {
        app(ProcessCompetitionRegistrationReminders::class)->run(new TenantExecutionContext($context->organizationId, $context->requestId, 'UTC', 'fr'), $context->requestId);
    } elseif ($payload['operation'] === 'external_competition') {
        app(ExternalCompetitionService::class)->save($context, $payload['input'], $payload['competition'], null);
    } elseif ($payload['operation'] === 'external_competition_document') {
        app(ExternalCompetitionDocumentService::class)->save($context, $payload['competition'], $payload['document'], $payload['input'], null);
    } elseif ($payload['operation'] === 'correction') {
        app(MatchCorrectionService::class)->request($context, $payload['match'], $payload['input'], null);
    } elseif ($payload['operation'] === 'correction_apply') {
        app(MatchCorrectionService::class)->process($context, $payload['match'], $payload['correction']);
    } elseif ($payload['operation'] === 'notebook') {
        app(MatchNotebookService::class)->change($context, $payload['match'], $payload['entry'], $payload['input'], null);
    } elseif (str_starts_with($payload['operation'], 'result_')) {
        app(MatchResultService::class)->change($context, $payload['match'], $payload['input'], substr($payload['operation'], 7));
    } elseif (in_array($payload['operation'], ['game', 'score_rules'], true)) {
        app(MatchScoreService::class)->save($context, $payload['match'], $payload['input'], $payload['operation'] === 'score_rules', null);
    } elseif (in_array($payload['operation'], ['assign', 'substitute'], true)) {
        app(MatchLineupService::class)->change($context, $payload['match'], $payload['participant'], $payload['input'], $payload['operation'] === 'substitute', null);
    } elseif ($payload['operation'] === 'edit') {
        $service->save($context, $payload['input'], $payload['match']);
    } else {
        $service->transition($context, $payload['match'], $payload['input']);
    }
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 200], JSON_THROW_ON_ERROR)."\n");
} catch (DomainConflictException $exception) {
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 409, 'code' => $exception->errorCode()], JSON_THROW_ON_ERROR)."\n");
}
