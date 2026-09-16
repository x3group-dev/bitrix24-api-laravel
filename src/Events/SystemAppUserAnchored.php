<?php

namespace X3Group\Bitrix24\Events;

/**
 * Портал переведён на системного пользователя приложения: токены записаны, якорь взведён.
 *
 * Точка расширения для приложения — пакет не знает, что нужно сделать после переезда
 * конкретному приложению (выдать права, перестроить кеш, уведомить администратора).
 */
readonly class SystemAppUserAnchored
{
    public function __construct(
        public string $memberId,
        public int $systemUserId,
    ) {
    }
}
