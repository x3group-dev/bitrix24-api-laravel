<?php

return [
    'enabled' => (bool) env('STRUCTURED_LOG_ENABLED', false),
    'path' => env('STRUCTURED_LOG_PATH', storage_path('logs/structured/app.json')),
    'max_files' => (int) env('STRUCTURED_LOG_MAX_FILES', 14),
    'app' => env('APP_LOG_NAME') ?: env('APP_NAME', 'app'),
    'schema_version' => '1',
    'truncate_at' => (int) env('STRUCTURED_LOG_TRUNCATE_AT', 200),

    // Ключи, значения которых вырезаются всегда.
    'secret_keys' => ['auth', 'AUTH', 'access_token', 'refresh_token', 'application_token', 'webhook_token'],

    // Методы, ответы которых содержат ПД; и ключи ПД внутри них.
    'personal_data_methods' => ['user.get', 'user.current', 'user.search', 'user.admin', 'user.fields'],
    'personal_data_keys' => ['NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL', 'PERSONAL_MOBILE', 'PERSONAL_PHONE', 'WORK_PHONE', 'LOGIN'],
];
