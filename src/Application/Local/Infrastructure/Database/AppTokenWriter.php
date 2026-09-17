<?php

namespace X3Group\Bitrix24\Application\Local\Infrastructure\Database;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Illuminate\Support\Facades\DB;
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

    /**
     * Пишет app-токен портала при условии прав администратора. На портале с включённым
     * is_system_user токены доступа остаются прежними, а служебные поля строки
     * (application_token, domain, oauth_server_url) обновляются.
     *
     * Чтение флага и запись идут в одной транзакции под блокировкой строки: событие
     * ONAPPUSERREADY приходит в момент установки, то есть параллельная запись штатна.
     */
    public function saveIfAllowed(LocalAppAuth $auth, string $memberId, bool $isAdmin, ?int $userId = null): void
    {
        DB::transaction(function () use ($auth, $memberId, $isAdmin, $userId): void {
            $b24app = B24App::query()->where('member_id', $memberId)->lockForUpdate()->first();
            $appExists = $b24app !== null;

            if ($appExists && (bool) $b24app->is_system_user && (int) $b24app->user_id > 0) {
                $this->refreshServiceFields($b24app, $auth);

                $this->logger->notice('b24 app token: keep existing tokens (portal switched to system user)', [
                    'member_id' => $memberId,
                ]);

                return;
            }

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
                // Префикс записанного application_token: по нему сверяется подпись событий
                // портала, и по логу видно, какое значение туда попало на установке.
                'application_token_prefix' => substr((string) $auth->getApplicationToken(), 0, 6),
            ]);
        });
    }

    /**
     * Обновляет только служебные поля строки и только непустыми значениями: после
     * переустановки портал выдаёт новый application_token, без которого не проходят проверку
     * подписи события и следующее ONAPPUSERREADY.
     */
    private function refreshServiceFields(B24App $b24app, LocalAppAuth $auth): void
    {
        $applicationToken = trim((string) $auth->getApplicationToken());
        if ($applicationToken !== '') {
            $b24app->application_token = $applicationToken;
        }

        $domain = trim($auth->getDomainUrl());
        if ($domain !== '') {
            $b24app->domain = $domain;
        }

        $oauthServerUrl = trim((string) ($auth->toArray()['oauth_server_url'] ?? ''));
        if ($oauthServerUrl !== '') {
            $b24app->oauth_server_url = $oauthServerUrl;
        }

        if ($b24app->isDirty()) {
            $b24app->save();
        }
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
