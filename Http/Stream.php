<?php
declare(strict_types=1);

namespace CodeX\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class Stream implements StreamInterface
{
    private const string READ_MODES = '/[r\+]/';
    private const string WRITE_MODES = '/[wa\+x\+c\+]/';

    // Используем отдельные свойства для property hooks
    private mixed $_resource;
    public bool $_seekable {
        get {
            return $this->_seekable;
        }
    }
    public bool $_readable {
        get {
            return $this->_readable;
        }
    }
    public bool $_writable {
        get {
            return $this->_writable;
        }
    }

    // Property hooks
    public ?int $size {
        get => $this->getSize();
    }

    public bool $isSeekable {
        get => $this->_seekable;
    }

    public bool $isReadable {
        get => $this->_readable;
    }

    public bool $isWritable {
        get => $this->_writable;
    }

    private ?int $cachedSize = null;
    private bool $closed = false {
        get {
            return $this->closed;
        }
    }
    private array $metadata;

    public function __construct($resource = 'php://memory', string $mode = 'r+')
    {
        if (is_string($resource)) {
            $resource = $this->openStream($resource, $mode);
        }

        // КРИТИЧЕСКИ ВАЖНЫЙ ПОРЯДОК:
        $this->validateResource($resource);
        $this->_resource = $resource;
        $this->metadata = stream_get_meta_data($resource);
        $this->_seekable = $this->metadata['seekable'] ?? false;

        // Используем РЕАЛЬНЫЙ режим из метаданных
        $actualMode = $this->metadata['mode'] ?? $mode;
        $this->determineAccessModes($actualMode);
    }

    private function openStream(string $path, string $mode): mixed
    {
        set_error_handler(static function (int $errno, string $errstr) use ($path, $mode): bool {
            throw new RuntimeException(sprintf(
                'Не удалось открыть поток "%s" в режиме "%s": %s',
                $path, $mode, $errstr
            ));
        });

        try {
            $resource = fopen($path, $mode);
            if ($resource === false) {
                throw new RuntimeException(sprintf(
                    'Функция fopen() вернула false для пути "%s" с режимом "%s"',
                    $path, $mode
                ));
            }
            return $resource;
        } finally {
            restore_error_handler();
        }
    }

    private function validateResource($resource): void
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid stream type. Expected resource, got %s',
                get_debug_type($resource)
            ));
        }

        if (get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException(sprintf(
                'Invalid resource type: %s, expected "stream"',
                get_resource_type($resource)
            ));
        }
    }

    private function determineAccessModes(string $mode): void
    {
        $mode = strtolower(trim($mode));

        // Универсальный подход для всех режимов PHP
        $this->_readable = (bool)preg_match(self::READ_MODES, $mode) && !str_contains($mode, 'wb');
        $this->_writable = (bool)preg_match(self::WRITE_MODES, $mode) || str_contains($mode, '+');
    }

    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'rb+');
        if ($resource === false) {
            throw new RuntimeException('Failed to create temporary stream');
        }

        fwrite($resource, $content);
        rewind($resource);

        return new self($resource);
    }

    public static function fromFile(string $filename, string $mode = 'r'): self
    {
        return new self($filename, $mode);
    }

    public static function fromMemory(): self
    {
        return new self('php://memory', 'r+');
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if (is_resource($this->_resource)) {
            fclose($this->_resource);
        }

        $this->_resource = null;
        $this->cachedSize = null;
        $this->closed = true;
        $this->_readable = false;
        $this->_writable = false;
        $this->_seekable = false;
    }

    public function detach()
    {
        if ($this->closed) {
            return null;
        }

        $resource = $this->_resource;

        $this->_resource = null;
        $this->cachedSize = null;
        $this->closed = true;
        $this->_readable = false;
        $this->_writable = false;
        $this->_seekable = false;

        return $resource;
    }

    public function __toString(): string
    {
        if ($this->closed || !$this->_readable) {
            return '';
        }

        try {
            if ($this->_seekable) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (RuntimeException $e) {
            return '';
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->ensureAvailable();

        if (!$this->_seekable) {
            throw new RuntimeException('Stream is not seekable');
        }

        if (fseek($this->_resource, $offset, $whence) === -1) {
            throw new RuntimeException(sprintf(
                'Unable to seek to offset %d with whence %d',
                $offset, $whence
            ));
        }
    }

    private function ensureAvailable(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Stream is closed');
        }

        if (!is_resource($this->_resource)) {
            throw new RuntimeException('Stream resource is not available');
        }
    }

    public function getContents(): string
    {
        $this->ensureAvailable();

        if (!$this->_readable) {
            throw new RuntimeException('Stream is not readable');
        }

        $contents = stream_get_contents($this->_resource);

        if ($contents === false) {
            throw new RuntimeException('Failed to get stream contents');
        }

        return $contents;
    }

    public function getSize(): ?int
    {
        if ($this->closed) {
            return null;
        }

        if ($this->cachedSize !== null) {
            return $this->cachedSize;
        }

        if (!is_resource($this->_resource)) {
            return null;
        }

        $stats = @fstat($this->_resource);
        if ($stats === false) {
            return null;
        }

        return $this->cachedSize = $stats['size'] ?? null;
    }

    public function tell(): int
    {
        $this->ensureAvailable();

        $position = ftell($this->_resource);
        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position');
        }

        return $position;
    }

    public function getMetadata($key = null)
    {
        if ($this->closed) {
            return $key ? null : [];
        }

        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }

    public function __clone()
    {
        throw new RuntimeException('Stream cloning is not supported');
    }

    public function copyTo(StreamInterface $target, int $chunkSize = 8192): void
    {
        if (!$this->_readable) {
            throw new RuntimeException('Source stream is not readable');
        }

        if (!$target->_writable) {
            throw new RuntimeException('Target stream is not writable');
        }

        if ($this->_seekable) {
            $this->rewind();
        }

        while (!$this->eof()) {
            $chunk = $this->read($chunkSize);
            if ($chunk !== '') {
                $target->write($chunk);
            }
        }
    }

    public function eof(): bool
    {
        if ($this->closed) {
            return true;
        }

        return feof($this->_resource);
    }

    public function read($length): string
    {
        $this->ensureAvailable();

        if (!$this->_readable) {
            throw new RuntimeException('Stream is not readable');
        }

        if ($length < 0) {
            throw new InvalidArgumentException('Length must be non-negative');
        }

        $result = fread($this->_resource, $length);

        if ($result === false) {
            throw new RuntimeException('Failed to read from stream');
        }

        return $result;
    }

    public function write($string): int
    {
        $this->ensureAvailable();

        if (!$this->_writable) {
            throw new RuntimeException('Stream is not writable');
        }

        $this->cachedSize = null;

        $bytesWritten = fwrite($this->_resource, $string);

        if ($bytesWritten === false) {
            throw new RuntimeException('Failed to write to stream');
        }

        return $bytesWritten;
    }

}