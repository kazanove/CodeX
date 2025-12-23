<?php
declare(strict_types=1);

namespace CodeX\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

class ListenerProvider implements ListenerProviderInterface
{
    /**
     * @var array<class-string, list<Closure>>
     */
    private array $listeners = [];

    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function getListenersForEvent(object $event): iterable
    {
        $class = $event::class;
        return $this->listeners[$class] ?? [];
    }
}