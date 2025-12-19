<?php
declare(strict_types=1);

namespace CodeX\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

class Uri implements UriInterface
{
    // Приватное хранилище состояния
    public string $scheme {
        get => $this->_scheme;
    }
    public string $host {
        get => $this->_host;
    }
    public ?int $port {
        get => $this->_port;
    }
    public string $path {
        get => $this->_path;
    }
    public string $query {
        get => $this->_query;
    }
    public string $fragment {
        get => $this->_fragment;
    }
    private string $_scheme;
    private string $_host;

    // Публичные свойства с геттерами
    private ?int $_port;
    private string $_path;
    private string $_query;
    private string $_fragment;
    private string $_user;
    private string $_pass;

    public function __construct(string $scheme = '', string $host = '', ?int $port = null, string $path = '/', string $query = '', string $fragment = '', string $user = '', string $pass = '')
    {
        $this->_scheme = $this->filterScheme($scheme);
        $this->_host = $this->filterHost($host);
        $this->_port = $this->filterPort($port);
        $this->_path = $this->filterPath($path);
        $this->_query = $this->filterQueryOrFragment($query);
        $this->_fragment = $this->filterQueryOrFragment($fragment);
        $this->_user = $user;
        $this->_pass = $pass;
    }

    // --- Вспомогательные методы валидации ---
    private function filterScheme(string $scheme): string
    {
        $scheme = strtolower($scheme);
        if ('' !== $scheme && !preg_match('/^[a-z][a-z0-9+\-.]*$/', $scheme)) {
            throw new InvalidArgumentException('Invalid URI scheme');
        }
        return $scheme;
    }

    private function filterHost(string $host): string
    {
        if ('' === $host) {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '[' . $host . ']';
        }
        return strtolower($host);
    }

    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid port');
        }
        return $port;
    }

    private function filterPath(string $path): string
    {
        return preg_replace_callback('/[^a-z0-9\-._~!$&\'()*+,;=:@\/%]/i', static fn($m) => rawurlencode($m[0]), $path);
    }

    private function filterQueryOrFragment(string $str): string
    {
        return preg_replace_callback('/[^a-z0-9\-._~!$&\'()*+,;=:@?\/%]/i', static fn($m) => rawurlencode($m[0]), $str);
    }

    // --- Реализация UriInterface ---
    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme) {
            $uri .= $this->scheme . ':';
        }
        if ($authority = $this->getAuthority()) {
            $uri .= '//' . $authority;
        }
        $uri .= $this->path;
        if ($this->query) {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment) {
            $uri .= '#' . $this->fragment;
        }
        return $uri;
    }

    public function getAuthority(): string
    {
        $authority = $this->host;
        if ($userInfo = $this->getUserInfo()) {
            $authority = $userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }
        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->_user . ($this->_pass ? ':' . $this->_pass : '');
    }

    // --- Неизменяемые методы ---

    public function withScheme($scheme): UriInterface
    {
        $new = clone $this;
        $new->_scheme = $this->filterScheme($scheme);
        return $new;
    }

    public function withUserInfo($user, $password = null): UriInterface
    {
        $new = clone $this;
        $new->_user = $user;
        $new->_pass = $password ?? '';
        return $new;
    }

    public function withHost($host): UriInterface
    {
        $new = clone $this;
        $new->_host = $this->filterHost($host);
        return $new;
    }

    public function withPort($port): UriInterface
    {
        $new = clone $this;
        $new->_port = $this->filterPort($port);
        return $new;
    }

    public function withPath($path): UriInterface
    {
        $new = clone $this;
        $new->_path = $this->filterPath($path);
        return $new;
    }

    public function withQuery($query): UriInterface
    {
        $new = clone $this;
        $new->_query = $this->filterQueryOrFragment($query);
        return $new;
    }

    public function withFragment($fragment): UriInterface
    {
        $new = clone $this;
        $new->_fragment = $this->filterQueryOrFragment($fragment);
        return $new;
    }
}