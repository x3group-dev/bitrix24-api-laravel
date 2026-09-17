<?php

namespace X3Group\Bitrix24\Events;

/**
 * Портал переведён на системного пользователя приложения: токены записаны, флаг
 * is_system_user включён.
 *
 * Точка расширения для приложения — пакет не знает, что после перехода нужно сделать
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
