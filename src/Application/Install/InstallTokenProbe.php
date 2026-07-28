<?php

namespace X3Group\Bitrix24\Application\Install;

use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;

/**
 * Шина для ПРОБНОГО вызова установки — того, которым выясняют, кто ставит приложение.
 *
 * Проба идёт до проверки админства, поэтому её шина НЕ несёт слушателя «записать
 * обновлённый токен в b24_apps»: иначе токен, обновившийся прямо на пробном вызове, уехал
 * бы в b24_apps мимо
 * {@see \X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter} — мимо
 * и проверки прав, и записи владельца.
 *
 * Терять такой рефреш тоже нельзя: Битрикс отзывает refresh_token сразу после обмена, и
 * исходный токен запроса после рефреша мёртв. Поэтому проба запоминает обновлённый токен в
 * памяти и отдаёт его дальше — в b24_apps ({@see self::tokenForStorage}) и рабочему
 * клиенту ({@see self::tokenForClient}).
 */
final class InstallTokenProbe
{
    private ?AuthToken $renewedToken = null;

    private readonly EventDispatcherAdapter $eventDispatcher;

    public function __construct()
    {
        $this->eventDispatcher = new EventDispatcherAdapter();
        $this->eventDispatcher->listen(
            AuthTokenRenewedEvent::class,
            function (AuthTokenRenewedEvent $authTokenRenewedEvent): void {
                $this->renewedToken = $authTokenRenewedEvent->getRenewedToken()->authToken;
            },
        );
    }

    public function eventDispatcher(): EventDispatcherAdapter
    {
        return $this->eventDispatcher;
    }

    /**
     * Токен для рабочего клиента: обновлённый, если проба его получила, иначе исходный.
     */
    public function tokenForClient(AuthToken $requestToken): AuthToken
    {
        return $this->renewedToken ?? $requestToken;
    }

    /**
     * Токен для записи в b24_apps, приведённый к «placement-конвенции».
     *
     * Поле expires у двух токенов означает РАЗНОЕ, хотя оба приходят с expiresIn = null:
     * у токена из запроса установки это СРОК ЖИЗНИ в секундах (AUTH_EXPIRES), у
     * обновлённого — АБСОЛЮТНЫЙ timestamp.
     * {@see \X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppAuthDatabaseStorage::save}
     * различает конвенции по expiresIn, а раз он null в обоих случаях — безусловно считает
     * expires сроком жизни и пишет в колонку now() + expires. Отдать туда обновлённый
     * токен как есть значит сложить два timestamp-а.
     *
     * Поэтому срок жизни считается из того, что в токене есть: expiresIn, если он заполнен,
     * иначе остаток от абсолютного expires. Если рефреша не было, возвращается исходный
     * токен как есть.
     */
    public function tokenForStorage(AuthToken $requestToken): AuthToken
    {
        if ($this->renewedToken === null) {
            return $requestToken;
        }

        return new AuthToken(
            accessToken: $this->renewedToken->accessToken,
            refreshToken: $this->renewedToken->refreshToken,
            expires: $this->renewedToken->expiresIn ?? max(0, $this->renewedToken->expires - time()),
        );
    }
}
