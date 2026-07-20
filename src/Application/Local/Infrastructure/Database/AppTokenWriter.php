<?php

namespace X3Group\Bitrix24\Application\Local\Infrastructure\Database;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Psr\Log\LoggerInterface;
use X3Group\Bitrix24\Models\B24App;

class AppTokenWriter
{
    public function __construct(private LoggerInterface $logger) {}

    /** Писать app-токен: первая установка (нет строки) ИЛИ открывающий — админ. */
    public static function shouldWrite(bool $appExists, bool $isAdmin): bool
    {
        return !$appExists || $isAdmin;
    }

    public function saveIfAllowed(LocalAppAuth $auth, string $memberId, bool $isAdmin): void
    {
        $appExists = B24App::query()->where('member_id', $memberId)->exists();
        if (!self::shouldWrite($appExists, $isAdmin)) {
            $this->logger->notice('b24 app token: keep existing (non-admin overwrite blocked)', ['member_id' => $memberId]);
            return;
        }
        (new AppAuthDatabaseStorage($memberId))->save($auth);
        $this->logger->info('b24 app token: saved', ['member_id' => $memberId, 'first_install' => !$appExists, 'is_admin' => $isAdmin]);
    }
}
