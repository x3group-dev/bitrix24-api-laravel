<?php

namespace X3Group\Bitrix24\Logging;

use Bitrix24\SDK\Core\Contracts\CoreInterface;

/**
 * Единая точка «обернуть core в LoggingCore или нет», чтобы Bitrix24App
 * не тащил config-логику. Возвращает исходный core, если логирование выключено.
 */
class LoggingCoreFactory
{
    public static function wrap(CoreInterface $core, string $domain = ''): CoreInterface
    {
        if (!config('structured-logging.enabled')) {
            return $core;
        }

        return new LoggingCore($core, app()->make('log')->channel('structured'), $domain);
    }
}
