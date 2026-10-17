<?php

use App\Exceptions\DomainConflictException;
use App\Modules\Scheduling\Application\Services\CalendarInvitationService;
use App\Modules\Scheduling\Application\Services\ProcessCalendarInvitations;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! preg_match('/^ucg_cal004_concurrency_[a-f0-9]{12}$/D', config('database.connections.pgsql.database'))) {
    fwrite(STDERR, 'Isolated concurrency database required.');
    exit(2);
}
$payload = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
$barrier = $argv[2] ?? '';
if (! preg_match('/^ucg_cal004_go_[a-f0-9]{24}$/D', basename($barrier))) {
    throw new UnexpectedValueException('Isolated test barrier required.');
}
Carbon\Carbon::setTestNow($payload['now']);
CarbonImmutable::setTestNow($payload['now']);
$runId = (string) Str::uuid();
$context = new TenantContext($payload['org'], $payload['actor'], 'concurrency', 'UTC', 'fr', $runId);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$deadline = microtime(true) + 20;
while (! is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Test barrier was not released.');
    }
    usleep(10000);
    clearstatcache(true, $barrier);
}
try {
    $service = app(CalendarInvitationService::class);
    if ($payload['operation'] === 'issue') {
        $service->issue($context, $payload['activity'], $payload['input'], null);
    } elseif ($payload['operation'] === 'respond') {
        $service->respond($context, $payload['invitation'], $payload['input'], null);
    } else {
        app(ProcessCalendarInvitations::class)->run(new TenantExecutionContext($payload['org'], $runId, 'UTC', 'fr'), $runId);
    }
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 200], JSON_THROW_ON_ERROR)."\n");
} catch (DomainConflictException $error) {
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 409, 'code' => $error->errorCode()], JSON_THROW_ON_ERROR)."\n");
}
