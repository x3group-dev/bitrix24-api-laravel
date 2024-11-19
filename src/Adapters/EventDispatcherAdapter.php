<?php

namespace X3Group\Bitrix24\Adapters;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\QueuedClosure;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EventDispatcherAdapter implements EventDispatcherInterface
{
    private Dispatcher $dispatcher;

    public function __construct()
    {
        $this->dispatcher = resolve('events');
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $r = $this->dispatcher->dispatch($event);

        return $event;
    }

    public function listen(array|Closure|QueuedClosure|string $events, array|Closure|QueuedClosure|null|string $listener): void
    {
        $this->dispatcher->listen($events, $listener);
    }
}
