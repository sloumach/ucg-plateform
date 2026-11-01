<?php

use App\Modules\People\Infrastructure\Queue\ProcessPersonImport;
use App\Modules\Reporting\Infrastructure\Queue\BuildReportExport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\RedisQueue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

if (getenv('UCG_LOAD_CONCURRENT') !== 'true' || getenv('QUEUE_CONNECTION') !== 'redis'
    || getenv('REDIS_HOST') !== '127.0.0.1') {
    throw new RuntimeException('An owned Redis load runtime is required.');
}

$application = require __DIR__.'/load-application.php';
$application->make(Kernel::class)->bootstrap();

$queue = $application->make(Factory::class)->connection('redis');
if (! $queue instanceof RedisQueue) {
    throw new RuntimeException('The load audit requires a Redis queue.');
}
$pending = 0;
$reserved = 0;
foreach (['critical', 'default', 'notifications', 'broadcasts', 'reports'] as $name) {
    $pending += $queue->size($name);
    $reserved += $queue->reservedSize($name);
}

$jobs = $application->make(JobRepository::class);
$reservedImports = 0;
$waiting = $jobs->countPending();
if ($waiting > 2000) {
    throw new RuntimeException('The bounded pending load job audit would be truncated.');
}
for ($offset = -1; $offset < $waiting; $offset += 50) {
    $rows = $jobs->getPending((string) $offset);
    foreach ($rows as $job) {
        if ($job->status === 'reserved' && $job->name === ProcessPersonImport::class) {
            $reservedImports++;
        }
    }
    if ($rows->count() < 50) {
        break;
    }
}
$completed = $jobs->countCompleted();
if ($completed > 2000) {
    throw new RuntimeException('The bounded load job audit would be truncated.');
}
$windows = [];
for ($offset = -1; $offset < $completed; $offset += 50) {
    $rows = $jobs->getCompleted((string) $offset);
    foreach ($rows as $job) {
        if (! in_array($job->name, [ProcessPersonImport::class,
            BuildReportExport::class], true)) {
            continue;
        }
        if (! is_numeric($job->reserved_at) || ! is_numeric($job->completed_at)) {
            throw new RuntimeException('A completed load job has no measured execution window.');
        }
        $windows[] = ['name' => class_basename($job->name), 'started' => (float) $job->reserved_at,
            'ended' => (float) $job->completed_at];
    }
    if ($rows->count() < 50) {
        break;
    }
}
$supervisors = $application->make(SupervisorRepository::class)->all();
$workers = 0;
foreach ($supervisors as $supervisor) {
    $workers += array_sum($supervisor->processes);
}

echo json_encode(['pending' => $pending, 'reserved' => $reserved, 'reservedImports' => $reservedImports,
    'failed' => $jobs->countFailed(), 'completed' => $completed, 'windows' => $windows,
    'supervisors' => count($supervisors), 'workers' => $workers,
], JSON_THROW_ON_ERROR);
