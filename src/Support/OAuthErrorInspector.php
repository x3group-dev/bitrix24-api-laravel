<?php

namespace X3Group\Bitrix24\Support;

use Throwable;

/**
 * Разбор исключений OAuth-обновления токена.
 *
 * b24phpsdk на ответ сервера OAuth "NOT_INSTALLED" (приложение удалено с портала)
 * бросает обобщённый TransportException без отдельного типа исключения — поэтому
 * распознаём этот случай по тексту сообщения.
 */
class OAuthErrorInspector
{
    /**
     * Приложение удалено с портала (токен больше не может быть обновлён).
     */
    public static function isApplicationNotInstalled(Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'NOT_INSTALLED') !== false
            || stripos($message, 'Application not installed') !== false;
    }
}
