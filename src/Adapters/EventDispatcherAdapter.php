<?php

namespace X3Group\Bitrix24\Adapters;

use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\QueuedClosure;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Мост между событиями b24phpsdk и диспетчером событий Laravel.
 *
 * ВАЖНО про изоляцию токен-событий. Раньше адаптер брал ГЛОБАЛЬНЫЙ диспетчер
 * (resolve('events')) и в него же вешал слушателей, поэтому listen() регистрировал
 * слушателя на весь процесс. Слушатель бинда 'appEvents' («записать обновлённый
 * токен в b24_apps») оказывался зарегистрирован глобально и срабатывал на рефреш
 * ЛЮБОГО токена, включая пользовательский:
 *
 *   что-то строит Bitrix24App    -> резолвит 'appEvents'  (появился слушатель записи)
 *   следом строится Bitrix24User -> работа от имени сотрудника
 *   протухший токен сотрудника   -> рефреш -> AuthTokenRenewedEvent в общий диспетчер
 *   -> срабатывают ОБА слушателя -> токен сотрудника уезжает в b24_apps
 *
 * Портал оставался с не-админским app-токеном: админ-методы (userfieldconfig.*)
 * начинали падать «нет прав», а переустановка администратором помогала лишь до
 * первого действия любого сотрудника. Запись шла мимо AppTokenWriter, поэтому его
 * аудит молчал. В долгоживущем queue:work хуже: слушатели копятся между джобами,
 * и устаревший слушатель пишет чужой токен в чужую строку b24_users.
 *
 * Теперь слушатели живут в ПРИВАТНОМ диспетчере экземпляра — строго в границах своего
 * ServiceBuilder. Прочие события SDK по-прежнему пробрасываются в глобальный диспетчер,
 * чтобы не ломать общеприложенческие слушатели (например PortalDomainUrlChangedEvent).
 */
class EventDispatcherAdapter implements EventDispatcherInterface
{
    /**
     * События, которые НЕ покидают приватный диспетчер: их слушатели пишут токены и
     * обязаны быть привязаны к своему клиенту.
     */
    private const SCOPED_EVENTS = [
        AuthTokenRenewedEvent::class,
    ];

    private Dispatcher $scoped;

    private ?Dispatcher $global;

    /**
     * @param  Dispatcher|null  $globalDispatcher  диспетчер для «общих» событий; по умолчанию
     *                                             берётся из контейнера лениво, в момент отправки.
     */
    public function __construct(?Dispatcher $globalDispatcher = null)
    {
        $this->scoped = new Dispatcher();
        $this->global = $globalDispatcher;
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->scoped->dispatch($event);

        if (! $this->isScoped($event)) {
            $this->globalDispatcher()?->dispatch($event);
        }

        return $event;
    }

    public function listen(array|Closure|QueuedClosure|string $events, array|Closure|QueuedClosure|string|null $listener): void
    {
        $this->scoped->listen($events, $listener);
    }

    private function isScoped(object $event): bool
    {
        foreach (self::SCOPED_EVENTS as $scopedEvent) {
            if ($event instanceof $scopedEvent) {
                return true;
            }
        }

        return false;
    }

    private function globalDispatcher(): ?Dispatcher
    {
        if ($this->global instanceof Dispatcher) {
            return $this->global;
        }

        // Вне Laravel (юнит-тесты пакета) контейнера нет — работаем только с
        // приватным диспетчером.
        if (! function_exists('resolve')) {
            return null;
        }

        $dispatcher = resolve('events');

        return $this->global = $dispatcher instanceof Dispatcher ? $dispatcher : null;
    }
}
