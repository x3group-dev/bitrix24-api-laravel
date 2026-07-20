<?php

return [
    'client_id' => env('BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'),
    'client_secret' => env('BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'),
    'scope' => env('BITRIX24_PHP_SDK_APPLICATION_SCOPE'),
    'log_max_files' => env('BITRIX24_LOG_MAX_FILES', 3),

    /**
     * Установщики приложения (централизованная установка).
     *
     * Список class-string, каждый реализует
     * {@see \X3Group\Bitrix24\Contracts\Bitrix24Installer}.
     * Прогоняются {@see \X3Group\Bitrix24\Application\Install\AppSetupRunner}
     * при открытии install-страницы и на событии ONAPPINSTALL.
     */
    'installers' => [],
];
