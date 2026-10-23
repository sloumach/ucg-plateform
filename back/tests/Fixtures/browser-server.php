<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
$application = require __DIR__.'/browser-application.php';
if (! $application instanceof Application) {
    throw new RuntimeException('Laravel application unavailable.');
}
$application->handleRequest(Request::capture());
