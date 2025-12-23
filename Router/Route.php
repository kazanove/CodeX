<?php
declare(strict_types=1);

namespace CodeX\Router;

use Closure;

final class Route
{
    public string|Closure $handler;
    public array $middleware = [];
    public array $paramPatterns = [];
    public ?string $name = null;
    public array $openApi = [];

    public function __construct(string|Closure $handler)
    {
        $this->handler = $handler;
    }

    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    public function where(string $param, string $regex): self
    {
        $this->paramPatterns[$param] = $regex;
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function openApi(array $spec): self
    {
        $this->openApi = $spec;
        return $this;
    }
}