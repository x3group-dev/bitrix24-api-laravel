<?php

namespace X3Group\Bitrix24\Support;

use X3Group\Bitrix24\Models\B24App;

/**
 * Сверка домена портала из запроса с b24_apps.domain установки.
 */
final class PortalDomain
{
    /** Домен из запроса совпадает с доменом, записанным для member_id; без строки b24_apps — false. */
    public static function matches(?B24App $b24app, ?string $requestDomain): bool
    {
        if ($b24app === null) {
            return false;
        }

        $stored = self::normalize($b24app->domain);

        return $stored !== '' && $stored === self::normalize($requestDomain);
    }

    private static function normalize(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return rtrim($domain, '/');
    }
}
