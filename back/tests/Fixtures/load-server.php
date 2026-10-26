<?php

use App\Modules\Tenancy\Presentation\Http\Middleware\LimitTenantRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
/** @var Application $application */
$application = require __DIR__.'/load-application.php';
$kernel = $application->make(Kernel::class);
$kernel->bootstrap();
$bootstrapMilliseconds = (microtime(true) - LARAVEL_START) * 1000;
$queries = 0;
$queryMilliseconds = 0.0;
$groups = [];
$profiling = getenv('UCG_LOAD_PROFILE') === 'profile';
$application->make(DatabaseManager::class)->listen(function (QueryExecuted $event) use (&$queries, &$queryMilliseconds, &$groups, $profiling): void {
    $queries++;
    $queryMilliseconds += $event->time;
    if ($profiling) {
        $fingerprint = hash('sha256', $event->sql);
        if (! isset($groups[$fingerprint])) {
            preg_match('/\b(?:from|into|update)\s+"?([a-z_]+)"?/i', $event->sql, $table);
            $caller = 'framework';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 45) as $frame) {
                if (isset($frame['file']) && str_contains(str_replace('\\', '/', $frame['file']), '/app/Modules/')) {
                    $caller = basename($frame['file']).':'.($frame['line'] ?? 0);
                    break;
                }
            }
            $groups[$fingerprint] = ['fingerprint' => $fingerprint, 'table' => $table[1] ?? 'other',
                'caller' => $caller, 'count' => 0, 'ms' => 0.0];
        }
        $groups[$fingerprint]['count']++;
        $groups[$fingerprint]['ms'] += $event->time;
    }
});
$request = Request::capture();
$handlingStarted = microtime(true);
$response = $kernel->handle($request);
// Test-only counters: no SQL text, bindings, credentials or response bodies logged.
$response->headers->set('X-UCG-Query-Count', (string) $queries);
$response->headers->set('X-UCG-Query-Ms', (string) round($queryMilliseconds, 3));
$response->headers->set('X-UCG-Bootstrap-Ms', (string) round($bootstrapMilliseconds, 3));
$response->headers->set('X-UCG-Handler-Ms', (string) round((microtime(true) - $handlingStarted) * 1000, 3));
$refusal = $request->attributes->get(LimitTenantRequests::REFUSAL_ATTRIBUTE);
if (is_string($refusal)) {
    $response->headers->set('X-UCG-Rate-Refusal', $refusal);
}
$response->send();
if ($profiling && str_ends_with($request->path(), '/people')) {
    $root = getenv('UCG_BROWSER_ROOT');
    file_put_contents($root.'/query-profile.jsonl', json_encode(['groups' => array_values($groups)], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
}
$kernel->terminate($request, $response);
