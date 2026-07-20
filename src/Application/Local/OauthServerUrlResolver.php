<?php
namespace X3Group\Bitrix24\Application\Local;

class OauthServerUrlResolver
{
    public const string EAST = 'https://oauth.bitrix24.tech/';

    public static function fromServerEndpoint(?string $serverEndpoint): string
    {
        $serverEndpoint = trim((string)$serverEndpoint);
        if ($serverEndpoint === '') { return self::EAST; }
        $parts = parse_url($serverEndpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) { return self::EAST; }
        return $parts['scheme'] . '://' . $parts['host'] . '/';
    }

    public static function orDefault(?string $stored): string
    {
        $stored = trim((string)$stored);
        return $stored !== '' ? $stored : self::EAST;
    }
}
