<?php

use Illuminate\Contracts\Console\Kernel;

if (getenv('UCG_LOAD_CONCURRENT') !== 'true' || getenv('QUEUE_CONNECTION') !== 'redis'
    || getenv('REDIS_HOST') !== '127.0.0.1') {
    throw new RuntimeException('An owned Redis load runtime is required.');
}

$application = require __DIR__.'/load-application.php';

exit($application->make(Kernel::class)->call('horizon', ['--environment' => 'local']));
