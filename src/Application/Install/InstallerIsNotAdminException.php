<?php

namespace X3Group\Bitrix24\Application\Install;

use RuntimeException;

/**
 * Установку прервали: приложение ставит не администратор портала.
 *
 * Владельцем портала (b24_apps.user_id) становится тот, кто поставил приложение, и его
 * токен потом обновляет app-токен по правилу 2
 * ({@see \X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter::propagateFromUser}).
 * Если владельцем окажется рядовой сотрудник, портал получит не-админский app-токен:
 * админ-методы (userfieldconfig.*) начнут падать «нет прав», а бэкофилл такого владельца
 * осознанно отказывается назначать. Поэтому проверка админства идёт на КАЖДОМ пути
 * установки, включая самую первую установку портала.
 *
 * Отдельный класс нужен, чтобы потребители ловили именно этот случай, а не разбирали
 * текст сообщения.
 */
class InstallerIsNotAdminException extends RuntimeException
{
    public function __construct(
        public readonly string $memberId,
        public readonly ?int $userId,
    ) {
        parent::__construct(sprintf(
            'install rejected: the portal owner must be a Bitrix24 administrator, but member_id=%s was installed by non-admin user_id=%s',
            $memberId,
            $userId === null ? 'unknown' : (string) $userId,
        ));
    }
}
