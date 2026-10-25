<?php

use Illuminate\Foundation\Application;

// No load process may bootstrap a development connection or an unbounded profile.
if (! in_array(getenv('UCG_LOAD_PROFILE'), ['smoke', 'profile', 'volume', 'pressure'], true) || getenv('UCG_BROWSER_ENGINE') !== 'pgsql'
    || getenv('DB_CONNECTION') !== 'pgsql') {
    throw new RuntimeException('An isolated PostgreSQL load profile is required.');
}

$application = require __DIR__.'/browser-application.php';
if (! $application instanceof Application) {
    throw new RuntimeException('Laravel application unavailable.');
}

return $application;
