<?php

namespace X3Group\Bitrix24\Application\Install;

use RuntimeException;

/**
 * Установку прервали: ставящий не может стать владельцем портала.
 *
 * Причин две — «ставит не админ» и «ставящего не удалось опознать», — но обе ведут в одно
 * состояние: портал без пригодного владельца. Поэтому класс один; разбирать текст
 * сообщения не нужно, для этого есть {@see self::$memberId} и {@see self::$userId}.
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
     * Профиль пришёл без ID: записать владельца нечем, а установка с user_id = NULL
     * оставила бы портал в состоянии, при котором правило 2 не сработает никогда.
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
