<?php

namespace X3Group\Bitrix24\Support;

/**
 * Определяет владельца access-токена Bitrix24.
 *
 * В токене 8 hex-цифр по смещению 24 содержат ID пользователя, которому он выдан.
 * Значение переживает рефреш: обновлённый токен остаётся токеном того же человека.
 */
class TokenOwner
{
    private const OWNER_OFFSET = 24;

    private const OWNER_LENGTH = 8;

    /**
     * @return int|null ID владельца либо null, если формат не распознан
     */
    public static function fromAccessToken(?string $accessToken): ?int
    {
        if ($accessToken === null || strlen($accessToken) < self::OWNER_OFFSET + self::OWNER_LENGTH) {
            return null;
        }

        $segment = substr($accessToken, self::OWNER_OFFSET, self::OWNER_LENGTH);

        if (!ctype_xdigit($segment)) {
            return null;
        }

        $userId = (int) hexdec($segment);

        return $userId > 0 ? $userId : null;
    }
}
