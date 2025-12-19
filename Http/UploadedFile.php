<?php
declare(strict_types=1);

namespace CodeX\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

class UploadedFile implements UploadedFileInterface
{
    private const array ERRORS = [
        UPLOAD_ERR_OK,
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE,
        UPLOAD_ERR_PARTIAL,
        UPLOAD_ERR_NO_FILE,
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION,
    ];

    // === 1. БАЗОВЫЕ ПРИВАТНЫЕ СВОЙСТВА ===
    private ?string $file = null;
    private ?StreamInterface $stream = null;
    private ?int $_size = null;
    private int $_error;
    private ?string $_clientFilename = null;
    private ?string $_clientMediaType = null;
    private bool $moved {
        get {
            return $this->moved;
        }
    }

    // === 2. ПУБЛИЧНЫЕ СВОЙСТВА С ГЕТТЕРАМИ ===
    public ?int $size {
        get => $this->_size;
    }
    public int $error {
        get => $this->_error;
    }
    public ?string $clientFilename {
        get => $this->_clientFilename;
    }
    public ?string $clientMediaType {
        get => $this->_clientMediaType;
    }

    public function __construct(
        $streamOrFile,
        ?int $size,
        int $errorStatus,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        if (!in_array($errorStatus, self::ERRORS, true)) {
            throw new InvalidArgumentException('Invalid error status for UploadedFile');
        }

        $this->_error = $errorStatus;
        $this->_size = $size;
        $this->_clientFilename = $clientFilename;
        $this->_clientMediaType = $clientMediaType;
        $this->moved = ($errorStatus !== UPLOAD_ERR_OK);

        if ($errorStatus === UPLOAD_ERR_OK) {
            if (is_string($streamOrFile)) {
                $this->file = $streamOrFile;
            } elseif ($streamOrFile instanceof StreamInterface) {
                $this->stream = $streamOrFile;
                // Автоопределение размера из потока
                if ($size === null && $streamOrFile->getSize() !== null) {
                    $this->_size = $streamOrFile->getSize();
                }
            } else {
                throw new InvalidArgumentException('Invalid stream or file provided');
            }
        }
    }

    // === 3. МЕТОДЫ ИНТЕРФЕЙСА PSR-7 ===
    public function getSize(): ?int { return $this->size; }
    public function getError(): int { return $this->error; }
    public function getClientFilename(): ?string { return $this->clientFilename; }
    public function getClientMediaType(): ?string { return $this->clientMediaType; }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Cannot retrieve stream after file has been moved');
        }
        if ($this->_error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot retrieve stream due to upload error: ' . $this->getErrorMessage());
        }
        if ($this->stream === null && $this->file !== null) {
            $this->stream = new Stream($this->file, 'r+');
        }
        if ($this->stream === null) {
            throw new RuntimeException('No stream available');
        }
        return $this->stream;
    }

    private function getErrorMessage(): string
    {
        return match ($this->_error) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
            default => 'Unknown error',
        };
    }

    /**
     * @throws Throwable
     */
    public function moveTo($targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('File has already been moved');
        }
        if ($this->_error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file due to upload error: ' . $this->getErrorMessage());
        }
        if (!is_string($targetPath) || $targetPath === '') {
            throw new InvalidArgumentException('Invalid path provided');
        }

        $targetPath = $this->normalizePath($targetPath);
        $targetDir = dirname($targetPath);

        // Создание директории, если не существует
        if (!file_exists($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException(sprintf('Failed to create directory "%s"', $targetDir));
        }

        if (!is_writable($targetDir)) {
            throw new RuntimeException(sprintf('Target directory "%s" is not writable', $targetDir));
        }

        // Стратегия выбора метода копирования
        $fileSize = $this->_size ?? 0;
        $useStreaming = $fileSize > 10 * 1024 * 1024; // Стриминг для файлов >10MB

        try {
            if ($this->file !== null) {
                // Стратегия 1: Работа с физическим файлом (move_uploaded_file)
                $this->movePhysicalFile($targetPath);
            } elseif ($useStreaming) {
                // Стратегия 2: Стриминг для больших файлов
                $this->streamToFile($targetPath);
            } else {
                // Стратегия 3: Полная загрузка для небольших файлов
                $this->loadEntireFile($targetPath);
            }

            $this->moved = true;
        } catch (Throwable $e) {
            // Очистка частично записанного файла
            if (file_exists($targetPath)) {
                @unlink($targetPath);
            }
            throw $e;
        }
    }

    /**
     * Стриминг для больших файлов (память: O(1), время: O(n))
     */
    private function streamToFile(string $targetPath): void
    {
        $stream = $this->getStream();
        $stream->rewind();

        // Используем системный буфер для максимальной производительности
        $targetResource = fopen($targetPath, 'wb');
        if ($targetResource === false) {
            throw new RuntimeException(sprintf('Failed to open target file "%s" for writing', $targetPath));
        }

        $bufferSize = 8192; // 8KB - оптимальный размер буфера
        $bytesWritten = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read($bufferSize);
            if ($chunk === '') {
                break;
            }

            $written = fwrite($targetResource, $chunk);
            if ($written === false || $written < strlen($chunk)) {
                throw new RuntimeException(sprintf(
                    'Failed to write chunk to "%s" at position %d',
                    $targetPath,
                    $bytesWritten
                ));
            }

            $bytesWritten += $written;
        }

        fclose($targetResource);
        $stream->close();
        $this->stream = null;
    }

    /**
     * Перемещение физического файла (самый эффективный способ)
     */
    private function movePhysicalFile(string $targetPath): void
    {
        if (!is_uploaded_file($this->file)) {
            throw new RuntimeException('File is not a valid uploaded file');
        }

        if (!move_uploaded_file($this->file, $targetPath)) {
            throw new RuntimeException(sprintf('Failed to move uploaded file to "%s"', $targetPath));
        }

        $this->file = null;
    }

    /**
     * Полная загрузка для небольших файлов (проще и быстрее для <10MB)
     */
    private function loadEntireFile(string $targetPath): void
    {
        $stream = $this->getStream();
        $stream->rewind();
        $content = $stream->getContents();

        if (file_put_contents($targetPath, $content) === false) {
            throw new RuntimeException(sprintf('Failed to write file to "%s"', $targetPath));
        }

        $stream->close();
        $this->stream = null;
    }

    private function normalizePath(string $path): string
    {
        // Удаляем попытки выхода за пределы базовой директории
        $path = str_replace(['\\', '//'], '/', $path);
        $path = preg_replace('#/\.{1,2}(/|$)#', '/', $path);
        return rtrim($path, '/') ?: '/';
    }

    // === 4. ДОПОЛНИТЕЛЬНЫЕ МЕТОДЫ ДЛЯ УДОБСТВА ===

    public function getFilePath(): ?string
    {
        return $this->file;
    }
}