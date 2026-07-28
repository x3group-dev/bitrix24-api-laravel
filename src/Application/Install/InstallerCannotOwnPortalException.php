<?php

namespace X3Group\Bitrix24\Application\Install;

use RuntimeException;

/**
 * Установку прервали: ставящий не может стать владельцем портала.
 *
 * Владельцем портала (b24_apps.user_id) становится тот, кто поставил приложение, и его
 * токен потом обновляет app-токен по правилу 2
 * ({@see \X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter::propagateFromUser}).
 * Если владельцем окажется рядовой сотрудник, портал получит не-админский app-токен:
 * админ-методы (userfieldconfig.*) начнут падать «нет прав», а бэкофилл такого владельца
 * осознанно отказывается назначать. Поэтому проверка идёт на КАЖДОМ пути установки,
 * включая самую первую установку портала.
 *
 * Причин отказа две — «ставит не админ» и «ставящего не удалось опознать», — но ведут они
 * в одно и то же состояние: портал без пригодного владельца. Поэтому класс один, и
 * потребителю достаточно поймать его один раз; причина видна в сообщении, а разбирать
 * текст не нужно — для этого есть {@see self::$memberId} и {@see self::$userId}.
 */
class InstallerCannotOwnPortalException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $memberId,
        public readonly ?int $userId,
    ) {
        parent::__construct($message);
    }

    public static function notAnAdmin(string $memberId, ?int $userId): self
    {
        return new self(
            sprintf(
                'install rejected: the portal owner must be a Bitrix24 administrator, but member_id=%s was installed by non-admin user_id=%s',
                $memberId,
                $userId === null ? 'unknown' : (string) $userId,
            ),
            $memberId,
            $userId,
        );
    }

    /**
     * Профиль пришёл без ID. Записать владельца нечем, а «успешная» установка с
     * user_id = NULL — ровно то молчаливое мёртвое состояние, ради устранения которого
     * владелец и вводится: правило 2 для такого портала не сработает никогда, и узнать
     * об этом можно будет только от клиента.
     */
    public static function notIdentified(string $memberId): self
    {
        return new self(
            sprintf(
                'install rejected: cannot record the portal owner for member_id=%s, the Bitrix24 profile came back without a user id',
                $memberId,
            ),
            $memberId,
            null,
        );
    }
}
