<?php
declare(strict_types=1);

namespace CodeX\Http;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class Request implements RequestInterface
{
    // Приватные свойства для хранения состояния
    private string $_method;
    private UriInterface $_uri;
    private ?string $_requestTarget = null;
    private array $_headers;
    private StreamInterface $_body;
    private string $_protocolVersion;

    // Публичные свойства с безопасными геттерами
    public string $method {
        get => $this->_method;
    }

    public UriInterface $uri {
        get => $this->_uri;
    }

    public array $headers {
        get => $this->_headers;
    }

    public StreamInterface $body {
        get => $this->_body;
    }

    public string $protocolVersion {
        get => $this->_protocolVersion;
    }

    public function __construct(
        string $method,
               $uri,
        array $headers = [],
               $body = null,
        string $version = '1.1'
    ) {
        $this->_method = $this->filterMethod($method);

        if (!$uri instanceof UriInterface) {
            if (!is_string($uri)) {
                throw new InvalidArgumentException('URI must be a string or UriInterface');
            }
            $uri = new Uri($uri);
        }

        $this->_uri = $uri;
        $this->_body = $this->determineBody($body);
        $this->_protocolVersion = $version;
        $this->_headers = $this->normalizeHeaders($headers);
    }

    private function filterMethod(string $method): string
    {
        static $validMethods = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH'];
        $method = strtoupper($method);
        if (!in_array($method, $validMethods, true) && !preg_match('/^[A-Z]+$/', $method)) {
            throw new InvalidArgumentException('Invalid HTTP method');
        }
        return $method;
    }

    private function determineBody($body): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            return $body;
        }

        if ($body !== null && !is_string($body)) {
            throw new InvalidArgumentException(sprintf(
                'Body must be a string, null, or StreamInterface, got %s',
                get_debug_type($body)
            ));
        }

        $stream = new Stream('php://memory', 'r+');
        if (is_string($body)) {
            $stream->write($body);
            $stream->rewind();
        }
        return $stream;
    }

    private function validateHeaderName(string $name): void
    {
        // Разрешаем символ подчеркивания для совместимости с RFC 7230
        if (!preg_match('/^[a-zA-Z0-9\'`~!#$%&*+.^_|_-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid header name: {$name}");
        }
    }

    private function normalizeHeaderValue($value): array
    {
        return is_array($value) ? $value : [$value];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $this->validateHeaderName($name);
            $lname = strtolower($name);
            $normalized[$lname] = $this->normalizeHeaderValue($value);
        }
        return $normalized;
    }

    public function getRequestTarget(): string
    {
        return $this->_requestTarget ?? ($this->_requestTarget = $this->generateRequestTarget());
    }

    private function generateRequestTarget(): string
    {
        $target = $this->_uri->getPath() ?: '/';
        if ($query = $this->_uri->getQuery()) {
            $target .= '?' . $query;
        }
        return $target;
    }

    public function withRequestTarget($requestTarget): RequestInterface
    {
        if (preg_match('#[\s\x00]#u', $requestTarget)) {
            throw new InvalidArgumentException('Invalid request target');
        }
        $new = clone $this;
        $new->_requestTarget = $requestTarget;
        return $new;
    }

    public function withMethod($method): RequestInterface
    {
        $new = clone $this;
        $new->_method = $this->filterMethod($method);
        return $new;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface
    {
        $new = clone $this;
        $new->_uri = $uri;

        if (!$preserveHost) {
            $host = $uri->getHost();
            $port = $uri->getPort();

            // Поддержка IPv6 адресов
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $host = "[{$host}]";
            }

            // Обработка стандартных портов
            $isDefaultPort = ($port === null) ||
                ($uri->getScheme() === 'http' && $port === 80) ||
                ($uri->getScheme() === 'https' && $port === 443);

            $hostHeader = $isDefaultPort ? $host : "{$host}:{$port}";
            $new->_headers['host'] = [$hostHeader];
        }

        return $new;
    }

    // Реализация PSR-7 через публичные свойства
    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function hasHeader($name): bool
    {
        return isset($this->_headers[strtolower($name)]);
    }

    public function getHeader($name): array
    {
        $name = strtolower($name);
        return $this->_headers[$name] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader($name, $value): RequestInterface
    {
        $name = (string)$name;
        $this->validateHeaderName($name);

        $new = clone $this;
        $lname = strtolower($name);
        $new->_headers[$lname] = $this->normalizeHeaderValue($value);
        return $new;
    }

    public function withAddedHeader($name, $value): RequestInterface
    {
        $name = (string)$name;
        $this->validateHeaderName($name);

        $lname = strtolower($name);
        $new = clone $this;
        $new->_headers[$lname] = array_merge(
            $new->_headers[$lname] ?? [],
            $this->normalizeHeaderValue($value)
        );
        return $new;
    }

    public function withoutHeader($name): RequestInterface
    {
        $lname = strtolower($name);
        if (!isset($this->_headers[$lname])) {
            return clone $this;
        }

        $new = clone $this;
        unset($new->_headers[$lname]);
        return $new;
    }

    public function withBody(StreamInterface $body): RequestInterface
    {
        $new = clone $this;
        $new->_body = $body;
        return $new;
    }

    public function withProtocolVersion($version): RequestInterface
    {
        $new = clone $this;
        $new->_protocolVersion = $version;
        return $new;
    }
}