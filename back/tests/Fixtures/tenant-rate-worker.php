<?php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Presentation\Http\Middleware\LimitTenantRequests;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql'
    || ! preg_match('/^ucg_rate_race_[a-f0-9]{12}$/D', DB::connection()->getDatabaseName())) {
    throw new UnexpectedValueException('Isolated PostgreSQL rate test database required.');
}
$payload = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
if (! is_array($payload) || ! is_string($payload['org'] ?? null) || ! Str::isUuid($payload['org'])
    || ! is_int($payload['actor'] ?? null) || $payload['actor'] < 1 || ! in_array($payload['maximum'] ?? null, [1, 2], true)) {
    throw new UnexpectedValueException('A closed rate test scenario is required.');
}
$barrier = $argv[2] ?? '';
if (! preg_match('/^ucg_rate_go_[a-f0-9]{24}$/D', basename($barrier))) {
    throw new UnexpectedValueException('Isolated test barrier required.');
}
config(['tenancy.cache_store' => 'database', 'tenancy.limits.requests_per_minute' => $payload['maximum']]);
$actor = User::query()->whereKey($payload['actor'])->firstOrFail();
$context = new TenantContext($payload['org'], $actor->id, 'rate-test', 'UTC', 'fr', (string) Str::uuid());
$request = Request::create('/api/v1/tenants/'.$context->organizationId.'/context');
$request->attributes->set(TenantContext::ATTRIBUTE, $context);
$request->setUserResolver(fn () => $actor);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$deadline = microtime(true) + 20;
while (! is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Rate test barrier was not released.');
    }
    usleep(10000);
    clearstatcache(true, $barrier);
}
$response = app(LimitTenantRequests::class)->handle($request, fn () => new Response('', 200));
fwrite(STDOUT, 'RESULT:'.json_encode(['status' => $response->getStatusCode(),
    'reason' => $request->attributes->get(LimitTenantRequests::REFUSAL_ATTRIBUTE)], JSON_THROW_ON_ERROR)."\n");
