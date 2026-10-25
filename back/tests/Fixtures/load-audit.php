<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

/** @var Application $application */
$application = require __DIR__.'/load-application.php';
$application->make(Kernel::class)->bootstrap();
$root = getenv('UCG_BROWSER_ROOT');
if (! is_string($root)) {
    throw new RuntimeException('Load fixture root unavailable.');
}
$fixture = json_decode((string) file_get_contents($root.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($fixture) || ! in_array($fixture['profile'] ?? null, ['smoke', 'profile', 'volume', 'pressure'], true)
    || ! is_array($fixture['organizations'] ?? null)) {
    throw new RuntimeException('Load fixture unavailable.');
}
$database = $application->make(DatabaseManager::class)->connection();
$untouched = [];
foreach (['beta', 'gamma'] as $label) {
    $organization = $fixture['organizations'][$label] ?? null;
    if (! is_string($organization)) {
        throw new RuntimeException('Load tenant unavailable.');
    }
    $people = $database->table('people')->where('organization_id', $organization)->orderBy('id')->get(['id', 'version', 'updated_at']);
    $untouched[$label] = ['people' => $people->count(), 'digest' => hash('sha256', $people->toJson()),
        'matches' => $database->table('matches')->where('organization_id', $organization)->count(),
        'games' => $database->table('match_game_instances')->where('organization_id', $organization)->count(),
        'exports' => $database->table('report_exports')->where('organization_id', $organization)->count(),
        'artifacts' => $database->table('tenant_artifacts')->where('organization_id', $organization)->count()];
}
echo json_encode(['untouched' => $untouched, 'failedJobs' => $database->table('failed_jobs')->count(),
    'pendingJobs' => $database->table('jobs')->count(),
    'alphaExports' => $database->table('report_exports')->where('organization_id', $fixture['organizations']['alpha'])
        ->get(['status', 'row_count'])->toArray(),
    'alphaPeople' => $database->table('people')->where('organization_id', $fixture['organizations']['alpha'])->count(),
    'alphaImports' => $database->table('person_imports')->where('organization_id', $fixture['organizations']['alpha'])
        ->get(['status', 'row_count'])->toArray()], JSON_THROW_ON_ERROR);
