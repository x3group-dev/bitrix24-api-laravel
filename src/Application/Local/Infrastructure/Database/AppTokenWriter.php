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
     * этот пользователь и есть владелец портала.
     *
     * $ownerUserId === null означает «владелец не установлен или не доверен» — тогда не
     * пишем ничего (fail-closed).
     */
    public static function shouldPropagateFromUser(?int $ownerUserId, int $userId): bool
    {
        return $ownerUserId !== null && $ownerUserId === $userId;
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
     * Правило 2: переносит обновлённый токен ВЛАДЕЛЬЦА портала в b24_apps.
     *
     * Владелец читается из колонки b24_apps.user_id и НИКОГДА не выводится из самого
     * записываемого токена — иначе проверка стала бы тавтологией и пропускала бы любого.
     *
     * Меняет только сам токен и сбрасывает счётчик ошибок. Владелец (user_id), домен,
     * application_token и oauth_server_url не трогаются — владелец меняется исключительно
     * при установке приложения либо ремонтом
     * {@see \X3Group\Bitrix24\Console\Commands\ReanchorAppTokenCommand}.
     *
     * Уровни отказов различаются: «обновляет не владелец» — notice (на здоровом флоте
     * таких записей около нуля), «владелец не установлен (NULL)» — debug (безопасный
     * fail-closed отказ, на флоте в тысячи порталов он был бы шумом).
     */
    public function propagateFromUser(string $memberId, int $userId, AuthToken $token): void
    {
        $b24app = B24App::query()->where('member_id', $memberId)->first();

        if ($b24app === null) {
            return;
        }

        $ownerUserId = $b24app->user_id === null ? null : (int) $b24app->user_id;

        if (!self::shouldPropagateFromUser($ownerUserId, $userId)) {
            $context = [
                'member_id' => $memberId,
                'owner_user_id' => $ownerUserId,
                'user_id' => $userId,
            ];

            if ($ownerUserId === null) {
                $this->logger->debug('b24 app token: propagation skipped (portal owner not established)', $context);
            } else {
                $this->logger->notice('b24 app token: propagation blocked (refresh by non-owner)', $context);
            }

            return;
        }

        $b24app->access_token = $token->accessToken;
        $b24app->refresh_token = $token->refreshToken;
        $b24app->expires = $token->expires;
        // Запасное значение 3600 — та же конвенция, что в AppAuthDatabaseStorage,
        // Bitrix24App::renewTokens и RemoveUninstalledPortals; менять её нужно везде сразу.
        $b24app->expires_in = $token->expiresIn ?? 3600;
        $b24app->error_update = 0;
        $b24app->save();

        $this->logger->info('b24 app token: propagated from owner', [
            'member_id' => $memberId,
            'user_id' => $userId,
        ]);
    }
}
