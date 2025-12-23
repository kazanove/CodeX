<?php
declare(strict_types=1);

namespace CodeX\Router;

use LogicException;

final class Node
{
    public array $staticChildren = [];

    public array $variableChildren = [];

    public array $routes = [];

    public function addRoute(string $method, array $pathSegments, Route $route, int $index = 0): void
    {
        if ($index === count($pathSegments)) {
            if (isset($this->routes[$method])) {
                throw new LogicException("Route for $method {$this->reconstructPath($pathSegments)} already exists.");
            }
            $this->routes[$method] = $route;
            return;
        }

        $segment = $pathSegments[$index];
        if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
            $paramName = substr($segment, 1, -1);
            $regex = $route->paramPatterns[$paramName] ?? '[^/]+';
            $key = $regex;
            if (!isset($this->variableChildren[$key])) {
                $this->variableChildren[$key] = new VariableSegment($regex, $paramName);
            }
            $this->variableChildren[$key]->node->addRoute($method, $pathSegments, $route, $index + 1);
        } else {
            if (!isset($this->staticChildren[$segment])) {
                $this->staticChildren[$segment] = new self();
            }
            $this->staticChildren[$segment]->addRoute($method, $pathSegments, $route, $index + 1);
        }
    }

    /**
     * @return array{Route, array<string, string>}|null
     */
    public function match(string $method, array $pathSegments, array &$params = [], int $index = 0): ?array
    {
        if ($index === count($pathSegments)) {
            if (isset($this->routes[$method])) {
                return [$this->routes[$method], $params];
            }
            if ($method === 'HEAD' && isset($this->routes['GET'])) {
                return [$this->routes['GET'], $params];
            }
            return null;
        }

        $segment = $pathSegments[$index];

        if (isset($this->staticChildren[$segment])) {
            $result = $this->staticChildren[$segment]->match($method, $pathSegments, $params, $index + 1);
            if ($result !== null) {
                return $result;
            }
        }

        foreach ($this->variableChildren as $varSeg) {
            if (preg_match($varSeg->pattern, $segment)) {
                $old = $params[$varSeg->paramName] ?? null;
                $params[$varSeg->paramName] = $segment;
                $result = $varSeg->node->match($method, $pathSegments, $params, $index + 1);
                if ($result !== null) {
                    return $result;
                }
                if ($old !== null) {
                    $params[$varSeg->paramName] = $old;
                } else {
                    unset($params[$varSeg->paramName]);
                }
            }
        }

        return null;
    }

    public function collectMethods(array $pathSegments, int $index = 0): array
    {
        $methods = array_keys($this->routes);

        if ($index >= count($pathSegments)) {
            return $methods;
        }

        $segment = $pathSegments[$index];

        if (isset($this->staticChildren[$segment])) {
            $methods = array_merge($methods, $this->staticChildren[$segment]->collectMethods($pathSegments, $index + 1));
        }

        foreach ($this->variableChildren as $varSeg) {
            if (preg_match($varSeg->pattern, $segment)) {
                $childMethods = $varSeg->node->collectMethods($pathSegments, $index + 1);
                if ($childMethods !== []) {
                    array_push($methods, ...$childMethods);
                }
            }
        }

        return $methods;
    }

    private function reconstructPath(array $segments): string
    {
        return '/' . implode('/', $segments);
    }
}