<?php
declare(strict_types=1);

namespace CodeX\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

readonly class Dispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ListenerProviderInterface $listenerProvider
    ) {}

    public function dispatch(object $event): object
    {
        foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->propagationStopped) {
                break;
            }
            $listener($event);
        }
        return $event;
    }
}