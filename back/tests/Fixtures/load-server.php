<?php

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
$application->make(DatabaseManager::class)->listen(function (QueryExecuted $event) use (&$queries, &$queryMilliseconds): void {
    $queries++;
    $queryMilliseconds += $event->time;
});
$request = Request::capture();
$handlingStarted = microtime(true);
$response = $kernel->handle($request);
// Test-only counters: no SQL text, bindings, credentials or response bodies logged.
$response->headers->set('X-UCG-Query-Count', (string) $queries);
$response->headers->set('X-UCG-Query-Ms', (string) round($queryMilliseconds, 3));
$response->headers->set('X-UCG-Bootstrap-Ms', (string) round($bootstrapMilliseconds, 3));
$response->headers->set('X-UCG-Handler-Ms', (string) round((microtime(true) - $handlingStarted) * 1000, 3));
$response->send();
$kernel->terminate($request, $response);
