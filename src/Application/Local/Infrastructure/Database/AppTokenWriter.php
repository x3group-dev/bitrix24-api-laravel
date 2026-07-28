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
     * Причины отказа разделены и по сообщению, и по уровню:
     *
     * «обновляет не установщик» — notice. На здоровом флоте это должно быть около нуля,
     * и каждый случай — либо попытка подмены app-токена, либо протухший user_id, из-за
     * которого блокируется законный рефреш. Ровно этого сигнала не хватало во время
     * инцидента (о поломке узнавали от клиента), поэтому он обязан оставаться громким.
     *
     * «владелец не установлен (NULL)» — debug. Это безопасный fail-closed отказ, и узнать
     * о нём можно одним запросом (select count(*) from b24_apps where user_id is null) —
     * строка в логе на каждый рефреш ничего не добавляет к факту, лежащему в колонке.
     * При этом объём таков, что notice здесь превратился бы не в строчку лога, а в сам
     * лог: у порталов, где никто не открывал фронтенд приложения, b24_users не заполняется
     * вовсе, так что user_id остаётся NULL, а порталов около 25 тысяч.
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
                $this->logger->debug('b24 app token: propagation skipped (portal owner not established)', $context);
            } else {
                $this->logger->notice('b24 app token: propagation blocked (refresh by non-installer)', $context);
            }

            return;
        }

        $b24app->access_token = $token->accessToken;
        $b24app->refresh_token = $token->refreshToken;
        $b24app->expires = $token->expires;
        // Замерено на этом наборе, обе подстановки прогнаны:
        //   `?? 3600` -> `?? 999`                        — OK (94 tests, 263 assertions);
        //   всё выражение -> `max(0, $token->expires - time())` — 1 failure.
        // То есть незакреплено ровно запасное ЗНАЧЕНИЕ: до него не доходит ни один тест.
        // Само выражение закреплено — токен размещения приходит с заполненным expiresIn,
        // и он обязан доехать до колонки как есть (test_the_placement_token_leaves_both_
        // rows_expiring_together).
        //
        // Значение оставлено ради единообразия: ровно так же считают пять соседних мест —
        // AppAuthDatabaseStorage::saveRenewedToken, UserAuthDatabaseStorage::saveRenewedToken,
        // Bitrix24App::renewTokens, Bitrix24User::renewTokens, RemoveUninstalledPortals.
        // Одна конвенция из шести, живущая по-своему, хуже шести одинаковых; менять —
        // так все сразу и отдельной задачей.
        $b24app->expires_in = $token->expiresIn ?? 3600;
        $b24app->error_update = 0;
        $b24app->save();

        $this->logger->info('b24 app token: propagated from installer', [
            'member_id' => $memberId,
            'user_id' => $userId,
        ]);
    }
}
