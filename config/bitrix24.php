<?php

return [
    'client_id' => env('BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID'),
    'client_secret' => env('BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET'),
    'scope' => env('BITRIX24_PHP_SDK_APPLICATION_SCOPE'),
    'log_max_files' => env('BITRIX24_LOG_MAX_FILES', 3),

    /**
     * Потолки исходящих REST-запросов, секунды.
     *
     * `timeout` — таймаут простоя (ожидание очередной порции данных),
     * `max_duration` — жёсткий потолок всего запроса. Второй обязателен:
     * без него портал, который принял соединение и отвечает по капле,
     * держит php-fpm воркер сколько угодно. Ноль и отрицательные значения
     * игнорируются, потому что в Symfony ноль означает «без ограничения» —
     * см. {@see \X3Group\Bitrix24\Core\B24HttpClientFactory}.
     */
    'http' => [
        'timeout' => env('BITRIX24_HTTP_TIMEOUT', 60),
        'max_duration' => env('BITRIX24_HTTP_MAX_DURATION', 120),
    ],

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
