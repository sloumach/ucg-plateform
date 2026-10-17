<?php

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Services\MatchService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
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
    if ($payload['operation'] === 'edit') {
        $service->save($context, $payload['input'], $payload['match']);
    } else {
        $service->transition($context, $payload['match'], $payload['input']);
    }
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 200], JSON_THROW_ON_ERROR)."\n");
} catch (DomainConflictException $exception) {
    fwrite(STDOUT, 'RESULT:'.json_encode(['status' => 409, 'code' => $exception->errorCode()], JSON_THROW_ON_ERROR)."\n");
}
