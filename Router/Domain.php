<?php
declare(strict_types=1);

namespace CodeX\Router;

use Psr\Http\Message\ServerRequestInterface;

class Domain
{
    private array $domains = [];
    private ?Node $defaultDomain = null;
    private array $fallbacks = [];

    public function addRoute(string $domain, string $method, array $pathSegments, Route $route): void
    {
        $node = $domain === '' ? $this->getDefaultNode() : $this->getOrCreateDomainNode($domain);
        $node->addRoute($method, $pathSegments, $route);
    }

    public function setFallback(string $domain, Route $route): void
    {
        $this->fallbacks[$domain] = $route;
    }

    public function match(ServerRequestInterface $request): ?array
    {
        $host = $request->getUri()->getHost();
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $segments = ($path === '/') ? [] : explode('/', trim($path, '/'));

        // Точный домен
        if (isset($this->domains[$host])) {
            if ($match = $this->domains[$host]->match($method, $segments)) {
                return $match;
            }
            if (isset($this->fallbacks[$host])) {
                return [$this->fallbacks[$host], []];
            }
        }

        if ($this->defaultDomain) {
            if ($match = $this->defaultDomain->match($method, $segments)) {
                return $match;
            }
            if (isset($this->fallbacks[''])) {
                return [$this->fallbacks[''], []];
            }
        }

        return null;
    }

    public function getAllMethodsForPath(ServerRequestInterface $request): array
    {
        $host = $request->getUri()->getHost();
        $path = $request->getUri()->getPath();
        $segments = ($path === '/') ? [] : explode('/', trim($path, '/'));

        $methods = [];
        if (isset($this->domains[$host])) {
            $methods = array_merge($methods, $this->domains[$host]->collectMethods($segments));
        }
        if ($this->defaultDomain) {
            $methods = array_merge($methods, $this->defaultDomain->collectMethods($segments));
        }
        return array_unique($methods);
    }

    private function getDefaultNode(): Node
    {
        if ($this->defaultDomain === null) {
            $this->defaultDomain = new Node();
        }
        return $this->defaultDomain;
    }

    private function getOrCreateDomainNode(string $domain): Node
    {
        if (!isset($this->domains[$domain])) {
            $this->domains[$domain] = new Node();
        }
        return $this->domains[$domain];
    }
}