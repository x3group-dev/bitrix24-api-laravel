<?php

namespace X3Group\Bitrix24\Application\Local\Infrastructure\Database;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Core\Credentials\AuthToken;
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

    /**
     * Правило 2: обновлённый пользовательский токен переносится в b24_apps, только если
     * этот пользователь и есть установщик приложения.
     *
     * $installerUserId === null означает «владелец не установлен или не доверен»
     * (например, бэкофилл увидел в токене не-админа) — тогда не пишем ничего.
     */
    public static function shouldPropagateFromUser(?int $installerUserId, int $userId): bool
    {
        return $installerUserId !== null && $installerUserId === $userId;
    }

    public function saveIfAllowed(LocalAppAuth $auth, string $memberId, bool $isAdmin, ?int $userId = null): void
    {
        $appExists = B24App::query()->where('member_id', $memberId)->exists();
        if (!self::shouldWrite($appExists, $isAdmin)) {
            $this->logger->notice('b24 app token: keep existing (non-admin overwrite blocked)', ['member_id' => $memberId]);
            return;
        }

        (new AppAuthDatabaseStorage($memberId))->save($auth);

        if ($userId !== null) {
            B24App::query()->where('member_id', $memberId)->update(['user_id' => $userId]);
        }

        $this->logger->info('b24 app token: saved', [
            'member_id' => $memberId,
            'first_install' => !$appExists,
            'is_admin' => $isAdmin,
            'user_id' => $userId,
        ]);
    }

    /**
     * Правило 2: переносит обновлённый токен установщика в b24_apps.
     *
     * Меняет только сам токен и сбрасывает счётчик ошибок. Владелец (user_id), домен,
     * application_token и oauth_server_url не трогаются — владелец меняется исключительно
     * при установке приложения.
     *
     * Отказ логируется notice'ом — так же, как отказ правила 1. Инцидент с подменой
     * app-токена был невидим ровно потому, что запись никак не отмечалась, и о поломке
     * узнавали от клиента. Причины отказа разделены: «обновляет не установщик» — это
     * возможный захват портала (или протухший user_id, из-за которого блокируется
     * законный рефреш), а «владелец не установлен» — ожидаемое состояние части флота
     * после бэкофилла. В одном сообщении первое утонуло бы во втором.
     */
    public function propagateFromUser(string $memberId, int $userId, AuthToken $token): void
    {
        $b24app = B24App::query()->where('member_id', $memberId)->first();

        if ($b24app === null) {
            return;
        }

        $installerUserId = $b24app->user_id === null ? null : (int) $b24app->user_id;

        if (!self::shouldPropagateFromUser($installerUserId, $userId)) {
            $context = [
                'member_id' => $memberId,
                'installer_user_id' => $installerUserId,
                'user_id' => $userId,
            ];

            if ($installerUserId === null) {
                $this->logger->notice('b24 app token: propagation blocked (portal owner not established)', $context);
            } else {
                $this->logger->notice('b24 app token: propagation blocked (refresh by non-installer)', $context);
            }

            return;
        }

        $b24app->access_token = $token->accessToken;
        $b24app->refresh_token = $token->refreshToken;
        $b24app->expires = $token->expires;
        $b24app->expires_in = $token->expiresIn ?? 3600;
        $b24app->error_update = 0;
        $b24app->save();

        $this->logger->info('b24 app token: propagated from installer', [
            'member_id' => $memberId,
            'user_id' => $userId,
        ]);
    }
}
