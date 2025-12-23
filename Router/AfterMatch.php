<?php
declare(strict_types=1);

namespace CodeX\Router;

use Psr\Http\Message\ServerRequestInterface;

class AfterMatch
{
    public function __construct(
        public ServerRequestInterface $request,
        public ?Route $route,
        public array $params
    ) {}
}