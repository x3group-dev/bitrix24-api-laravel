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
     * Осторожно, это не формальность: поле expires у двух токенов означает РАЗНОЕ, хотя
     * называется одинаково и в обоих случаях приходит без expiresIn.
     *
     *   - токен из запроса установки: expires — СРОК ЖИЗНИ в секундах (AUTH_EXPIRES),
     *     см. AuthToken::initFromPlacementRequest — три аргумента, expiresIn остаётся null;
     *   - обновлённый токен: expires — АБСОЛЮТНЫЙ timestamp, потому что рефреш собирается
     *     через RenewedAuthToken::initFromArray -> AuthToken::initFromArray, тоже три
     *     аргумента и тоже null в expiresIn. Во всём SDK expiresIn заполняет ровно один
     *     метод — initFromEventRequest для одноразовых токенов событий, к рефрешу
     *     отношения не имеющий.
     *
     * {@see \X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppAuthDatabaseStorage::save}
     * различает конвенции по expiresIn, а раз он null в обоих случаях, то безусловно
     * считает expires сроком жизни и пишет в колонку now() + expires. Отдать туда
     * обновлённый токен как есть — значит сложить два timestamp-а и припарковать портал
     * где-то в 2083 году. Тихо это не останется: {@see \X3Group\Bitrix24\Console\Commands\RemoveUninstalledPortals}
     * читает b24_apps.expires как абсолютный timestamp, решая, добит ли портал, — такой
     * портал не вычистится никогда.
     *
     * Поэтому срок жизни считается из того, что в токене действительно есть: expiresIn,
     * если он вдруг заполнен, иначе остаток от абсолютного expires. Константу сюда
     * подставлять нельзя — «ровно 3600» это свойство сегодняшнего Битрикса, а не токена.
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
            expires: $this->renewedToken->expiresIn ?? max(0, $this->renewedToken->expires - time()),
        );
    }
}
