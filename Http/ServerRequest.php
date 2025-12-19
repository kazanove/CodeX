<?php
declare(strict_types=1);

namespace CodeX\Http;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

class ServerRequest extends Request implements ServerRequestInterface
{
    // === 1. БАЗОВЫЕ ПРИВАТНЫЕ СВОЙСТВА ===
    private array $_serverParams;
    private array $_cookieParams;
    private array $_queryParams;
    private array $_uploadedFiles;
    private mixed $_parsedBody = null;
    private array $_attributes;

    // === 2. ПУБЛИЧНЫЕ СВОЙСТВА С ГЕТТЕРАМИ ===
    public array $serverParams {
        get => $this->_serverParams;
    }
    public array $cookieParams {
        get => $this->_cookieParams;
    }
    public array $queryParams {
        get => $this->_queryParams;
    }
    public array $uploadedFiles {
        get => $this->_uploadedFiles;
    }
    public mixed $parsedBody {
        get => $this->_parsedBody;
    }
    public array $attributes {
        get => $this->_attributes;
    }

    public function __construct(
        string $method,
               $uri,
        array $headers = [],
               $body = null,
        string $version = '1.1',
        array $serverParams = [],
        array $cookieParams = [],
        array $queryParams = [],
        array $uploadedFiles = [],
               $parsedBody = null,
        array $attributes = []
    ) {
        parent::__construct($method, $uri, $headers, $body, $version);

        $this->_serverParams = $serverParams;
        $this->_cookieParams = $cookieParams;
        $this->_queryParams = $queryParams;
        $this->_uploadedFiles = $this->validateUploadedFiles($uploadedFiles);
        $this->_parsedBody = $parsedBody;
        $this->_attributes = $attributes;
    }

    private function validateUploadedFiles(array $files): array
    {
        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $files[$key] = $this->validateUploadedFiles($file);
            } elseif (!$file instanceof UploadedFileInterface && $file !== null) {
                throw new InvalidArgumentException('Invalid uploaded file instance');
            }
        }
        return $files;
    }

    // === 3. МЕТОДЫ ИНТЕРФЕЙСА PSR-7 ===
    public function getServerParams(): array { return $this->serverParams; }
    public function getCookieParams(): array { return $this->cookieParams; }
    public function getQueryParams(): array { return $this->queryParams; }
    public function getUploadedFiles(): array { return $this->uploadedFiles; }
    public function getParsedBody() { return $this->parsedBody; }
    public function getAttributes(): array { return $this->attributes; }

    public function getAttribute($name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    // === 4. НЕИЗМЕНЯЕМЫЕ МЕТОДЫ ===
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $new = clone $this;
        $new->_cookieParams = $cookies;
        return $new;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $new = clone $this;
        $new->_queryParams = $query;
        return $new;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $new = clone $this;
        $new->_uploadedFiles = $this->validateUploadedFiles($uploadedFiles);
        return $new;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $new = clone $this;
        $new->_parsedBody = $data;
        return $new;
    }

    public function withAttribute($name, $value): ServerRequestInterface
    {
        $new = clone $this;
        $new->_attributes[$name] = $value;
        return $new;
    }

    public function withoutAttribute($name): ServerRequestInterface
    {
        if (!array_key_exists($name, $this->attributes)) {
            return clone $this;
        }
        $new = clone $this;
        unset($new->_attributes[$name]);
        return $new;
    }

    // === 5. ФАБРИЧНЫЙ МЕТОД С КОРРЕКТНОЙ ОБРАБОТКОЙ ЗАГОЛОВКОВ ===
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = self::createUriFromGlobals();
        $headers = self::getHeadersFromGlobals();
        $body = new Stream('php://input', 'r');
        $serverParams = $_SERVER;
        $cookieParams = $_COOKIE;
        $queryParams = $_GET;
        $uploadedFiles = self::normalizeFiles($_FILES);
        $parsedBody = self::parseBodyFromGlobals($headers);

        return new self(
            $method,
            $uri,
            $headers,
            $body,
            '1.1',
            $serverParams,
            $cookieParams,
            $queryParams,
            $uploadedFiles,
            $parsedBody
        );
    }

    private static function createUriFromGlobals(): UriInterface
    {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        $port = $_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $_SERVER['SCRIPT_NAME'] ?? '/';
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        // Обработка хоста с портом
        if (str_contains($host, ':')) {
            [$host, $portPart] = explode(':', $host, 2);
            if (ctype_digit($portPart)) {
                $port = (int)$portPart;
            }
        }

        return new Uri(
            scheme: $scheme,
            host: $host,
            port: (int)$port,
            path: $path,
            query: $queryString
        );
    }

    private static function getHeadersFromGlobals(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    private static function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $value) {
            if (!isset($value['tmp_name'])) {
                continue;
            }

            if (is_array($value['tmp_name'])) {
                $normalized[$key] = self::normalizeNestedFileArray($value);
            } else {
                $normalized[$key] = self::createUploadedFileFromSpec($value);
            }
        }
        return $normalized;
    }

    private static function normalizeNestedFileArray(array $value): array
    {
        $files = [];
        foreach ($value['tmp_name'] as $k => $tmpName) {
            $files[$k] = self::createUploadedFileFromSpec([
                'tmp_name' => $tmpName,
                'size'     => $value['size'][$k] ?? null,
                'error'    => $value['error'][$k] ?? UPLOAD_ERR_NO_FILE,
                'name'     => $value['name'][$k] ?? null,
                'type'     => $value['type'][$k] ?? null,
            ]);
        }
        return $files;
    }

    private static function createUploadedFileFromSpec(array $value): UploadedFileInterface
    {
        $error = $value['error'] ?? UPLOAD_ERR_NO_FILE;
        $size = $value['size'] ?? null;
        $name = $value['name'] ?? null;
        $type = $value['type'] ?? null;

        if ($error !== UPLOAD_ERR_OK) {
            return new UploadedFile(
                streamOrFile: '',
                size: $size,
                errorStatus: $error,
                clientFilename: $name,
                clientMediaType: $type
            );
        }

        return new UploadedFile(
            streamOrFile: $value['tmp_name'],
            size: $size,
            errorStatus: UPLOAD_ERR_OK,
            clientFilename: $name,
            clientMediaType: $type
        );
    }

    /**
     * @throws JsonException
     */
    private static function parseBodyFromGlobals(array $headers): mixed
    {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength === 0) {
            return null;
        }

        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return null;
        }

        // Используем метод PSR-7 для получения заголовка
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($input, $data);
            return $data;
        }

        if (stripos($contentType, 'application/json') !== false) {
            return json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        }

        // Для multipart/form-data можно добавить парсинг позже
        return null;
    }
}