<?php
declare(strict_types=1);

namespace CodeX\Http;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Response implements ResponseInterface
{
    private const array PHRASES = [// 1xx Informational
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',

        // 2xx Success
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-Status', 208 => 'Already Reported', 226 => 'IM Used',

        // 3xx Redirection
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',

        // 4xx Client Errors
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Payload Too Large', 414 => 'URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable', 417 => 'Expectation Failed', 418 => 'I\'m a teapot', 421 => 'Misdirected Request', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Too Early', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',

        // 5xx Server Errors
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required',];

    // === 1. БАЗОВЫЕ ПРИВАТНЫЕ СВОЙСТВА ===
    public int $statusCode {
        get => $this->_statusCode;
    }
    public string $reasonPhrase {
        get => $this->_reasonPhrase;
    }
    public string $protocolVersion {
        get => $this->_protocolVersion;
    }
    public array $headers {
        get => $this->_headers;
    }
    public StreamInterface $body {
        get => $this->_body;
    }

    // === 2. ПУБЛИЧНЫЕ СВОЙСТВА С ГЕТТЕРАМИ ===
    private int $_statusCode;
    private string $_reasonPhrase;
    private string $_protocolVersion;
    private array $_headers;
    private StreamInterface $_body;

    /**
     * @param int $status Статус код ответа
     * @param string $reasonPhrase Текстовое описание статуса (автоматически определяется, если не указано)
     * @param StreamInterface|null $body Тело ответа
     * @param array $headers Заголовки ответа
     * @param string $version Версия протокола
     */
    public function __construct(int $status = 200, string $reasonPhrase = '', ?StreamInterface $body = null, array $headers = [], string $version = '1.1')
    {
        $this->_statusCode = $this->validateStatus($status);

        if ($reasonPhrase === '' && isset(self::PHRASES[$status])) {
            $this->_reasonPhrase = self::PHRASES[$status];
        } else {
            $this->_reasonPhrase = $this->validateReasonPhrase($reasonPhrase);
        }

        $this->_protocolVersion = $this->validateProtocolVersion($version);
        $this->_headers = $this->validateAndNormalizeHeaders($headers);

        // Создание пустого потока по умолчанию
        if ($body === null) {
            $body = $this->createEmptyBody();
        }

        $this->_body = $body;

        // Автоматическое добавление Content-Type при наличии тела
        if (!$this->hasHeader('Content-Type') && $this->_body->getSize() > 0) {
            $this->_headers['content-type'] = ['text/html; charset=utf-8'];
        }

        // Автоматическое добавление Content-Length
        if (!$this->hasHeader('Content-Length')) {
            $size = $this->_body->getSize();
            if ($size !== null) {
                $this->_headers['content-length'] = [(string)$size];
            }
        }
    }

    // === 3. МЕТОДЫ ИНТЕРФЕЙСА PSR-7 ===

    /**
     * Валидация статус кода
     */
    private function validateStatus(int $status): int
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid status code. Must be between 100 and 599');
        }
        return $status;
    }

    /**
     * Валидация reason phrase
     */
    private function validateReasonPhrase(string $phrase): string
    {
        if (preg_match("#[\r\n]#", $phrase)) {
            throw new InvalidArgumentException('Reason phrase cannot contain newline characters');
        }
        return $phrase;
    }

    /**
     * Валидация версии протокола
     */
    private function validateProtocolVersion(string $version): string
    {
        if (!preg_match('/^(1\.[01]|2)$/', $version)) {
            throw new InvalidArgumentException('Invalid protocol version. Must be 1.0, 1.1, or 2');
        }
        return $version;
    }

    /**
     * Валидация и нормализация всех заголовков
     */
    private function validateAndNormalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $this->validateHeaderName($name);
            $normalized[strtolower($name)] = $this->validateHeaderValue($value);
        }

        return $normalized;
    }

    /**
     * Валидация имени заголовка
     */
    private function validateHeaderName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9\'`~!#$%&*+.^_|-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid header name: {$name}");
        }
    }

    /**
     * Валидация значения заголовка
     */
    private function validateHeaderValue($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item) && $item !== null) {
                throw new InvalidArgumentException('Header value must be a string, number, or null');
            }

            if (is_string($item) && preg_match("#[\r\n]#", $item)) {
                throw new InvalidArgumentException('Header value cannot contain newline characters');
            }
        }

        return array_map('strval', $value);
    }

    /**
     * Создает пустое тело ответа
     */
    private function createEmptyBody(): StreamInterface
    {
        $stream = new Stream('php://memory', 'wb+');
        $stream->rewind();
        return $stream;
    }

    /**
     * Проверяет наличие заголовка
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->_headers[strtolower($name)]);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * Возвращает значение заголовка в виде строки
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * Возвращает значение заголовка в виде массива
     */
    public function getHeader(string $name): array
    {
        $name = strtolower($name);
        return $this->_headers[$name] ?? [];
    }

    /**
     * Возвращает новую копию с измененной версией протокола
     */
    public function withProtocolVersion($version): ResponseInterface
    {
        $new = clone $this;
        $new->_protocolVersion = $this->validateProtocolVersion($version);
        return $new;
    }

    /**
     * Возвращает новую копию с добавленным значением заголовка
     */
    public function withAddedHeader($name, $value): ResponseInterface
    {
        $this->validateHeaderName($name);
        $value = $this->validateHeaderValue($value);

        $new = clone $this;
        $name = strtolower($name);

        if (isset($new->_headers[$name])) {
            $new->_headers[$name] = array_merge($new->_headers[$name], $value);
        } else {
            $new->_headers[$name] = $value;
        }

        return $new;
    }

    /**
     * Возвращает новую копию без указанного заголовка
     */
    public function withoutHeader($name): MessageInterface
    {
        $new = clone $this;
        unset($new->_headers[strtolower($name)]);
        return $new;
    }

    /**
     * Устанавливает JSON-контент
     * @throws JsonException
     */
    public function withJson(array $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $new = $this->withStatus($status);
        $new = $new->withHeader('Content-Type', 'application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            $new = $new->withHeader($name, $value);
        }

        $body = new Stream('php://memory', 'wb+');
        $body->write($json);
        $body->rewind();

        return $new->withBody($body);
    }

    /**
     * Возвращает новую копию с измененным статусом
     */
    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->_statusCode = $this->validateStatus($code);

        if ($reasonPhrase === '' && isset(self::PHRASES[$code])) {
            $new->_reasonPhrase = self::PHRASES[$code];
        } else {
            $new->_reasonPhrase = $this->validateReasonPhrase($reasonPhrase);
        }

        return $new;
    }

    /**
     * Возвращает новую копию с замененным заголовком
     */
    public function withHeader($name, $value): ResponseInterface
    {
        $this->validateHeaderName($name);
        $value = $this->validateHeaderValue($value);

        $new = clone $this;
        $new->_headers[strtolower($name)] = $value;
        return $new;
    }

    /**
     * Возвращает новую копию с измененным телом
     */
    public function withBody(StreamInterface $body): ResponseInterface
    {
        $new = clone $this;
        $new->_body = $body;

        // Обновление Content-Length при изменении тела
        $size = $body->getSize();
        if ($size !== null) {
            $new->_headers['content-length'] = [(string)$size];
        } else {
            unset($new->_headers['content-length']);
        }

        return $new;
    }

    /**
     * Устанавливает HTML-контент
     */
    public function withHtml(string $html, int $status = 200, array $headers = []): self
    {
        $new = $this->withStatus($status);
        $new = $new->withHeader('Content-Type', 'text/html; charset=utf-8');

        foreach ($headers as $name => $value) {
            $new = $new->withHeader($name, $value);
        }

        $body = new Stream('php://memory', 'wb+');
        $body->write($html);
        $body->rewind();

        return $new->withBody($body);
    }

    /**
     * Устанавливает редирект
     */
    public function withRedirect(string $url, int $status = 302): self
    {
        if (!in_array($status, [300, 301, 302, 303, 307, 308])) {
            throw new InvalidArgumentException('Invalid redirect status code');
        }

        return $this->withStatus($status)->withHeader('Location', $url);
    }

    /**
     * Отправляет ответ клиенту
     */
    public function send(): void
    {
        // Отправка статусной строки
        $statusLine = sprintf('HTTP/%s %d %s', $this->_protocolVersion, $this->_statusCode, $this->_reasonPhrase);

        if (!headers_sent()) {
            header($statusLine, true, $this->_statusCode);

            // Отправка заголовков в оригинальном регистре
            $originalHeaders = $this->getOriginalHeaders();
            foreach ($originalHeaders as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        // Отправка тела
        $body = $this->_body;
        $body->rewind();

        // Если тело уже отправлено напрямую (например, через flush()), пропускаем
        if (!connection_aborted() && !$this->isHeadRequest()) {
            echo $body->getContents();
        }
    }

    /**
     * Получает заголовки в оригинальном регистре
     */
    private function getOriginalHeaders(): array
    {
        $original = [];
        foreach ($this->_headers as $lowercaseName => $values) {
            // Восстанавливаем оригинальный регистр из стандартных заголовков
            $originalName = $this->normalizeHeaderName($lowercaseName);
            $original[$originalName] = $values;
        }
        return $original;
    }

    /**
     * Нормализует имя заголовка к стандартному виду (Content-Type вместо content-type)
     */
    private function normalizeHeaderName(string $name): string
    {
        $specialCases = ['etag' => 'ETag', 'www-authenticate' => 'WWW-Authenticate', 'content-md5' => 'Content-MD5', 'dnt' => 'DNT', 'tk' => 'Tk', 'x-requested-with' => 'X-Requested-With'];
        return $specialCases[$name] ?? implode('-', array_map('ucfirst', explode('-', $name)));
    }

    /**
     * Проверяет, является ли запрос HEAD
     */
    private function isHeadRequest(): bool
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD';
    }
}