<?php

return [
    'reminders' => [
        'enabled' => (bool) env('CONTRACT_REMINDERS_ENABLED', false),
        'email_enabled' => (bool) env('CONTRACT_REMINDERS_EMAIL_ENABLED', false),
        'mailer' => env('CONTRACT_REMINDERS_MAILER', 'smtp'),
    ],
];
