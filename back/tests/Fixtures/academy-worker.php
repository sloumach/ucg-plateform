<?php

use App\Exceptions\DomainConflictException;
use App\Modules\Academy\Application\Services\AcademyAttendanceService;
use App\Modules\Academy\Application\Services\AcademyCoachingService;
use App\Modules\Academy\Application\Services\AcademyService;
use App\Modules\Academy\Application\Services\AcademySessionService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! preg_match('/^ucg_aca001_race_[a-f0-9]{12}$/D', config('database.connections.pgsql.database'))) {
    fwrite(STDERR, 'Isolated academy concurrency database required.');
    exit(2);
}
$payload = json_decode($argv[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
$barrier = $argv[2] ?? '';
if (! preg_match('/^ucg_aca001_go_[a-f0-9]{24}$/D', basename($barrier))) {
    throw new UnexpectedValueException('Isolated academy test barrier required.');
}
$context = new TenantContext($payload['org'], $payload['actor'], 'concurrency', 'UTC', 'fr', (string) Str::uuid());
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$deadline = microtime(true) + 25;
while (! is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Academy test barrier was not released.');
    }
    usleep(10000);
    clearstatcache(true, $barrier);
}
try {
    if (in_array($payload['kind'], ['objective', 'feedback'], true)) {
        app(AcademyCoachingService::class)->save($context, $payload['kind'] === 'objective' ? 'objective' : 'shared', $payload['cohort'], $payload['cycle'], $payload['objective'], $payload['feedback'], $payload['input'], null);
    } elseif ($payload['kind'] === 'session') {
        app(AcademySessionService::class)->save($context, $payload['cohort'], $payload['cycle'], $payload['input'], $payload['id']);
    } elseif ($payload['kind'] === 'attendance') {
        app(AcademyAttendanceService::class)->save($context, $payload['cohort'], $payload['cycle'], $payload['session'], $payload['input']);
    } else {
        app(AcademyService::class)->save($context, $payload['kind'], $payload['input'], $payload['cohort'], $payload['id']);
    }
    $result = ['status' => 200];
} catch (DomainConflictException $exception) {
    $result = ['status' => 409, 'code' => $exception->errorCode()];
}
fwrite(STDOUT, 'RESULT:'.json_encode($result, JSON_THROW_ON_ERROR)."\n");
