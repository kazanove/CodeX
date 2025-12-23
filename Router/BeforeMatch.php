<?php
declare(strict_types=1);

namespace CodeX\Router;

use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\ServerRequestInterface;

class BeforeMatch implements StoppableEventInterface
{
    public function __construct(
        public ServerRequestInterface $request,
        public bool $propagationStopped = false {
            get {
                return $this->propagationStopped;
            }
        }
    ) {}

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}