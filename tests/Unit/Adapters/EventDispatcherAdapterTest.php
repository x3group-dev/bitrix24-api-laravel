<?php

namespace X3Group\Bitrix24\Tests\Unit\Adapters;

use Bitrix24\SDK\Application\ApplicationStatus;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Bitrix24\SDK\Events\PortalDomainUrlChangedEvent;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;

/**
 * Токен-события обязаны оставаться в границах своего ServiceBuilder.
 *
 * Пока адаптер вешал слушателей на глобальный диспетчер Laravel, слушатель бинда
 * 'appEvents' («записать обновлённый токен в b24_apps») срабатывал на рефреш
 * ЛЮБОГО токена — и токен обычного сотрудника затирал app-токен портала, ломая
 * админ-методы (userfieldconfig.*).
 */
class EventDispatcherAdapterTest extends TestCase
{
    private function renewedToken(string $accessToken): AuthTokenRenewedEvent
    {
        return new AuthTokenRenewedEvent(new RenewedAuthToken(
            authToken: new AuthToken(
                accessToken: $accessToken,
                refreshToken: $accessToken.'-refresh',
                expires: time() + 3600,
                expiresIn: 3600,
            ),
            memberId: 'member-1',
            clientEndpoint: 'https://portal.bitrix24.ru/rest/',
            serverEndpoint: 'https://oauth.bitrix24.tech/rest/',
            applicationStatus: ApplicationStatus::subscription(),
            domain: 'portal.bitrix24.ru',
        ));
    }

    public function test_token_listeners_are_scoped_to_their_own_instance(): void
    {
        $appEvents = new EventDispatcherAdapter(new Dispatcher());
        $userEvents = new EventDispatcherAdapter(new Dispatcher());

        $appSaw = [];
        $appEvents->listen(AuthTokenRenewedEvent::class, function (AuthTokenRenewedEvent $event) use (&$appSaw): void {
            $appSaw[] = $event->getRenewedToken()->authToken->accessToken;
        });

        $userSaw = [];
        $userEvents->listen(AuthTokenRenewedEvent::class, function (AuthTokenRenewedEvent $event) use (&$userSaw): void {
            $userSaw[] = $event->getRenewedToken()->authToken->accessToken;
        });

        // Рефреш пользовательского токена — только его слушатель.
        $userEvents->dispatch($this->renewedToken('user-token'));

        self::assertSame(['user-token'], $userSaw);
        self::assertSame([], $appSaw, 'рефреш пользовательского токена дошёл до слушателя app-токена (клоббер b24_apps)');
    }

    public function test_token_events_do_not_reach_the_global_bus(): void
    {
        $global = new Dispatcher();

        $globalSaw = [];
        $global->listen(AuthTokenRenewedEvent::class, function () use (&$globalSaw): void {
            $globalSaw[] = true;
        });

        (new EventDispatcherAdapter($global))->dispatch($this->renewedToken('some-token'));

        self::assertSame([], $globalSaw, 'токен-событие утекло в глобальный диспетчер');
    }

    public function test_other_events_are_forwarded_to_the_global_bus(): void
    {
        $global = new Dispatcher();

        $seen = [];
        $global->listen(PortalDomainUrlChangedEvent::class, function (PortalDomainUrlChangedEvent $event) use (&$seen): void {
            $seen[] = $event->getNewDomainUrlHost();
        });

        (new EventDispatcherAdapter($global))->dispatch(
            new PortalDomainUrlChangedEvent('https://old.bitrix24.ru', 'https://new.bitrix24.ru'),
        );

        self::assertSame(['new.bitrix24.ru'], $seen, 'общее событие не дошло до глобального диспетчера');
    }

    public function test_dispatch_returns_the_same_event_instance(): void
    {
        $event = $this->renewedToken('token');

        self::assertSame($event, (new EventDispatcherAdapter(new Dispatcher()))->dispatch($event));
    }
}
