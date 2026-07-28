<?php

namespace X3Group\Bitrix24\Application\Install;

use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;

/**
 * Шина для ПРОБНОГО вызова установки — того, которым выясняют, кто ставит приложение.
 *
 * Проба идёт до admin-gate'а, то есть в момент, когда ещё неизвестно, имеет ли ставящий
 * право стать владельцем портала. Поэтому её шина НЕ несёт слушателя «записать обновлённый
 * токен в b24_apps»: иначе протухший токен, обновившийся прямо на пробном вызове, уехал бы
 * в b24_apps мимо {@see \X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter}
 * — мимо и проверки админства, и записи владельца. На install-странице это ещё и токен
 * ЧУЖОГО пользователя: клиент там строится из PLACEMENT-запроса, то есть с токеном
 * открывшего страницу, а не портала. Изоляция шин из 3.3.1 тут не спасает: слушатель и
 * клиент — один и тот же экземпляр.
 *
 * Но и терять такой рефреш нельзя: Битрикс отзывает refresh_token сразу после обмена, так
 * что исходный токен из запроса после рефреша мёртв. Записать его в b24_apps значит
 * положить туда заведомо отозванный refresh_token и сломать портал до переустановки.
 * Поэтому проба не игнорирует событие, а ЗАПОМИНАЕТ обновлённый токен — в память, не в БД —
 * и отдаёт его дальше: в b24_apps ({@see self::tokenForStorage}) и рабочему клиенту
 * ({@see self::tokenForClient}), который доигрывает установку уже на шине 'appEvents'.
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
     * Осторожно, это не формальность. У токена из запроса установки expires — это СРОК
     * ЖИЗНИ в секундах, а expiresIn не заполнен; у обновлённого наоборот: expires —
     * абсолютный timestamp, expiresIn — срок жизни.
     * {@see \X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppAuthDatabaseStorage::save}
     * различает эти случаи по expiresIn и для непустого expiresIn кладёт в колонку expires
     * срок жизни, а в expires_in — timestamp, то есть меняет колонки местами. Отдать туда
     * обновлённый токен как есть значит записать портал с перепутанным сроком годности.
     *
     * Когда рефреша не было, метод возвращает исходный токен как есть — то есть на обычной
     * установке поведение не меняется ни на байт.
     */
    public function tokenForStorage(AuthToken $requestToken): AuthToken
    {
        if ($this->renewedToken === null) {
            return $requestToken;
        }

        return new AuthToken(
            accessToken: $this->renewedToken->accessToken,
            refreshToken: $this->renewedToken->refreshToken,
            expires: $this->renewedToken->expiresIn ?? 3600,
        );
    }
}
