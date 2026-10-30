<?php

if (getenv('UCG_DATA_REHEARSAL_CHILD') !== '1') {
    throw new RuntimeException('Use php artisan data:rehearse.');
}

$rehearsalToken = bin2hex(random_bytes(16));
$rehearsalEnvironment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_CONFIG_CACHE' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg-data002-'.$rehearsalToken.'.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'DB_FOREIGN_KEYS' => 'true', 'CACHE_STORE' => 'array', 'TENANCY_CACHE_STORE' => 'array', 'TENANCY_TEST_REDIS' => 'false', 'SESSION_DRIVER' => 'array',
    'OPS_BACKGROUND_MONITORING_ENABLED' => 'false',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'LOG_CHANNEL' => 'null', 'BCRYPT_ROUNDS' => '4', 'APP_MAINTENANCE_DRIVER' => 'file',
    'RETENTION_RESTORE_MODE' => 'false', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false', 'TEST_TOKEN' => 'data002-'.$rehearsalToken,
];
foreach ($rehearsalEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
