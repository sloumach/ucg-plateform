<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

$application = require __DIR__.'/browser-application.php';
if (! $application instanceof Application) {
    throw new RuntimeException('Laravel application unavailable.');
}
$kernel = $application->make(Kernel::class);
$kernel->bootstrap();
if (config('queue.default') !== 'database' || config('queue.connections.database.connection') !== null
    || config('database.default') !== 'sqlite' || ! $application->environment('testing')) {
    fwrite(STDERR, 'An isolated database queue is required.'.PHP_EOL);
    exit(1);
}
file_put_contents($application->storagePath('framework/browser-worker-ready'), 'ready', LOCK_EX);
exit($kernel->call('queue:work', [
    'connection' => 'database', '--queue' => 'default', '--sleep' => 1,
    '--max-time' => 600, '--no-interaction' => true,
]));
