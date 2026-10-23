<?php

use Illuminate\Foundation\Application;

// This entry point belongs only to the isolated browser harness, never public/index.php.
$root = getenv('UCG_BROWSER_ROOT');
$token = getenv('UCG_BROWSER_TOKEN');
$temporaryDirectory = realpath(sys_get_temp_dir());
if (! is_string($root) || ! is_string($token) || strlen($token) !== 64
    || realpath($root) !== $root || is_link($root)
    || realpath(dirname($root)) !== $temporaryDirectory
    || preg_match('/^ucg-browser-[a-zA-Z0-9]+$/D', basename($root)) !== 1
    || ! is_file($root.'/owner') || ! hash_equals($token, trim((string) file_get_contents($root.'/owner')))
    || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== $root.'/browser.sqlite' || getenv('DB_URL') !== ''
    || ! is_file($root.'/browser.sqlite') || is_link($root.'/browser.sqlite')
    || getenv('LARAVEL_STORAGE_PATH') !== $root.'/storage'
    || getenv('APP_CONFIG_CACHE') !== $root.'/config.php' || is_file($root.'/config.php')) {
    throw new RuntimeException('Isolated browser environment required; no application was booted.');
}
foreach (['APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php', 'APP_ROUTES_CACHE' => 'routes.php', 'APP_EVENTS_CACHE' => 'events.php'] as $variable => $filename) {
    if (getenv($variable) !== $root.'/'.$filename) {
        throw new RuntimeException('Isolated browser environment required; no application was booted.');
    }
}

require_once __DIR__.'/../../vendor/autoload.php';
$application = require __DIR__.'/../../bootstrap/app.php';
if (! $application instanceof Application) {
    throw new RuntimeException('Laravel application unavailable.');
}
$application->useEnvironmentPath($root);
$application->useStoragePath($root.'/storage');
if (preg_match('/^[A-Za-z]:[\\\\\/]/', $root) === 1) {
    $application->addAbsoluteCachePathPrefix(substr($root, 0, 3));
}

return $application;
