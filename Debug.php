<?php
declare(strict_types=1);

namespace CodeX;

use Error;
use ErrorException;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use ParseError;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;
use TypeError;

final class Debug
{
    private bool $inProduction;
    private array $beforeRender = [];
    private array $afterRender = [];
    private bool $preferJson;
    private array $sensitiveKeys = ['password', 'token', 'secret', 'auth', 'csrf'];
    private ?LoggerInterface $logger;
    private bool $logInProduction;

    public function __construct(bool $inProduction = false, bool $preferJson = false, ?LoggerInterface $logger = null, bool $logInProduction = true)
    {
        $this->inProduction = $inProduction;
        $this->preferJson = $preferJson;
        $this->logger = $logger;
        $this->logInProduction = $logInProduction;
    }

    public function pushBeforeRender(callable $fn): void
    {
        $this->beforeRender[] = $fn;
    }

    public function pushAfterRender(callable $fn): void
    {
        $this->afterRender[] = $fn;
    }

    public function register(): void
    {
        ini_set('display_errors', $this->inProduction ? '0' : '1');
        error_reporting(E_ALL);

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $this->handle(new ErrorException($message, 0, $severity, $file, $line));
            return true;
        });

        set_exception_handler(function (Throwable $e) {
            $this->handle($e);
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->handle(new ErrorException($error['message'], 0, $error['type'], $error['file'] ?? 'unknown', $error['line'] ?? 0));
            }
        });
    }

    private function handle(Throwable $e): void
    {
        if ($this->inProduction) {
            $this->renderProduction($e);
        }
        $wantsJson = $this->preferJson || $this->isAjax();
        try {
            $payload = $this->buildPayload($e);
        } catch (Throwable $buildError) {
            // Fallback для критических ошибок в самом обработчике
            $this->emergencyRender($e, $buildError);
            exit(1);
        }
       foreach ($this->beforeRender as $fn) {
            try {
                $fn($payload);
            } catch (Throwable) {
            }
        }

        if ($wantsJson) {
            $this->renderJson($payload);
        } else {
            $this->renderHtml($payload);
        }

        foreach ($this->afterRender as $fn) {
            try {
                $fn($payload);
            } catch (Throwable) {
            }
        }

        exit(1);
    }

    #[NoReturn]
    private function renderProduction(Throwable $e): void
    {
        // Логируем ошибку перед выводом пользователю
        $this->logError($e);

        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo "Internal Server Error\n";
        exit(1);
    }

    /**
     * Безопасное логирование с fallback-механизмами
     */
    private function logError(Throwable $e): void
    {
        if (!$this->logInProduction) {
            return;
        }

        $context = ['file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString(), 'environment' => $this->inProduction ? 'production' : 'development'];

        // Пытаемся использовать PSR-3 логгер
        if ($this->logger) {
            try {
                $this->logger->log($this->determineLogLevel($e), sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()), ['exception' => $e] + $context);
                return;
            } catch (Throwable $loggerError) {
                // Если логгер сам вызвал ошибку - используем встроенные механизмы
                error_log("Logger failure: " . $loggerError->getMessage());
            }
        }

        // Fallback на встроенные механизмы PHP
        $message = sprintf("[%s] %s in %s:%d\nTrace:\n%s", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

        if (function_exists('error_log')) {
            error_log($message);
        }

        // Дополнительный fallback для CLI
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
        }
    }

    /**
     * Определяет уровень логирования на основе типа исключения
     */
    private function determineLogLevel(Throwable $e): string
    {
        return ($e instanceof Error) ? LogLevel::CRITICAL : LogLevel::ERROR;
    }

    private function isAjax(): bool
    {
        if ($this->preferJson) {
            return true;
        }

        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhrHeader = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return (stripos($acceptHeader, 'application/json') !== false) || (strtolower($xhrHeader) === 'xmlhttprequest');
    }

    /**
     * @throws Throwable
     */
    private function buildPayload(Throwable $e): array
    {
        try {
            $trace = $this->normalizeTrace($e->getTrace(), $e->getFile(), $e->getLine());
            $frames = array_map(fn($f) => $this->decorateFrame($f), $trace);

            return ['type' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'code' => $this->readCodeExcerpt($e->getFile(), $e->getLine()), 'frames' => $frames, 'globals' => $this->getSanitizedGlobals(), 'time' => date('c'),];
        } catch (Throwable $buildError) {
            // Критическая ошибка при построении payload
            $this->logError($buildError);
            throw $buildError; // Позволяем обработчику ошибок перехватить это
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeTrace(array $trace, string $file, int $line): array
    {
        $first = ['file' => $file, 'line' => $line, 'function' => null, 'class' => null, 'type' => null, 'args' => []];
        return array_merge([$first], $trace);
    }

    private function decorateFrame(array $frame): array
    {
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? null;
        $frame['code'] = ($file && $line) ? $this->readCodeExcerpt($file, (int)$line, 5) : null;
        return $frame;
    }

    private function readCodeExcerpt(string $file, int $line, int $pad = 7): array
    {
        if (!file_exists($file) || !is_readable($file)) {
            return ['start' => $line, 'focus' => $line, 'lines' => []];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return ['start' => $line, 'focus' => $line, 'lines' => []];
        }
        $start = max(1, $line - $pad);
        $end = min(count($lines), $line + $pad);
        $out = [];
        for ($i = $start; $i <= $end; $i++) {
            $out[] = ['ln' => $i, 'code' => htmlspecialchars($lines[$i - 1] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'isFocus' => $i === $line];
        }
        return ['start' => $start, 'focus' => $line, 'lines' => $out];
    }

    /**
     * Безопасное получение и санитизация суперглобальных переменных
     */
    private function getSanitizedGlobals(): array
    {
        $sanitize = static function ($data) use (&$sanitize) {
            $sensitiveKeys = ['password', 'token', 'secret', 'auth', 'csrf'];
            if (is_array($data)) {
                $result = [];
                foreach ($data as $key => $value) {
                    $lowerKey = strtolower($key);
                    $isSensitive = array_filter($sensitiveKeys, static fn($s) => str_contains($lowerKey, $s));

                    if ($isSensitive) {
                        $result[$key] = '[REDACTED]';
                    } elseif (is_array($value) || is_object($value)) {
                        $result[$key] = $sanitize($value);
                    } else {
                        $result[$key] = is_string($value) ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
                    }
                }
                return $result;
            }
            return $data;
        };

        return ['_GET' => $sanitize($_GET ?? []), '_POST' => $sanitize($_POST ?? []), '_SERVER' => $sanitize(['REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null, 'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null, 'HTTP_ACCEPT' => $_SERVER['HTTP_ACCEPT'] ?? null, 'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? null,]), '_COOKIE' => $sanitize($_COOKIE ?? []), '_SESSION' => (session_status() === PHP_SESSION_ACTIVE) ? $sanitize($_SESSION) : null,];
    }

    private function emergencyRender(Throwable $mainError, Throwable $buildError): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "CRITICAL DEBUG ERROR:\n";
        echo "Main error: {$mainError->getMessage()}\n";
        echo "Debug system error: {$buildError->getMessage()}\n";
    }

    private function renderJson(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => ['type' => $payload['type'], 'message' => $payload['message'], 'file' => $payload['file'], 'line' => $payload['line'], 'frames' => array_map(static function ($f) {
            return ['file' => $f['file'] ?? null, 'line' => $f['line'] ?? null, 'function' => $f['function'] ?? null, 'class' => $f['class'] ?? null,];
        }, $payload['frames']),],], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** @param array<string,mixed> $payload */
    private function renderHtml(array $payload): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'");
        http_response_code(500);
        $title = htmlspecialchars($payload['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = htmlspecialchars($payload['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $file = htmlspecialchars($payload['file'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $line = (int)$payload['line'];

        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
        echo "<title>$type: $title</title>";
        echo '<style>
        :root{color-scheme:dark light}
        body{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; margin:0; background:#0f1220; color:#e8eaf6;}
        header{padding:16px 20px; background:#1b1f33; border-bottom:1px solid #303558;}
        .msg{font-size:18px;}
        .where{opacity:.85; margin-top:6px;}
        .wrap{display:grid; grid-template-columns: 1fr 1fr; gap:12px; padding:16px;}
        .card{background:#14182b; border:1px solid #2a2f52; border-radius:8px; overflow:auto;}
        .card h3{margin:0; padding:10px 12px; font-size:14px; background:#1b2038; border-bottom:1px solid #2a2f52;}
        pre{margin:0; padding:10px 12px; white-space:pre;}
        .code .ln{display:inline-block; width:3ch; opacity:.6; user-select:none}
        .focus{background:#2a325a;}
        .stack-item{border-bottom:1px solid #2a2f52;}
        .stack-item pre{padding:8px 12px;}
        .kv{display:grid; grid-template-columns: 200px 1fr; gap:8px; padding:10px 12px;}
        .kv div{padding:6px 8px; border-bottom:1px dashed #2a2f52;}
        .muted{opacity:.7}
        a{color:#87b3ff; text-decoration:none}
        .badge{display:inline-block; padding:2px 6px; background:#2a325a; border-radius:4px; margin-left:8px; font-size:12px; opacity:.8}
    </style></head><body>';

        echo "<header><div class='msg'>$type: $title</div><div class='where'>$file:$line</div></header>";
        echo "<div class='wrap'>";

        // Главный отрывок
        echo "<section class='card code'><h3>Code excerpt</h3><pre>";
        foreach ($payload['code']['lines'] as $ln) {
            $class = $ln['isFocus'] ? 'focus' : '';
            echo "<span class='ln $class'>" . str_pad((string)$ln['ln'], 3, ' ', STR_PAD_LEFT) . "</span> ";
            echo "<span class='$class'>" . $ln['code'] . "</span>\n";
        }
        echo "</pre></section>";

        // Стек вызовов
        echo "<section class='card'><h3>Stack trace</h3>";
        foreach ($payload['frames'] as $i => $f) {
            $fFile = htmlspecialchars($f['file'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
            $fLine = (int)($f['line'] ?? 0);
            $fn = htmlspecialchars($f['function'] ?? '', ENT_QUOTES, 'UTF-8');
            $cls = htmlspecialchars($f['class'] ?? '', ENT_QUOTES, 'UTF-8');
            $a = $f['type'] ?? '';
            echo "<div class='stack-item'><pre>#$i $cls$a$fn  <span class='muted'>$fFile:$fLine</span></pre>";
            if (!empty($f['code']['lines'])) {
                echo "<pre>";
                foreach ($f['code']['lines'] as $ln) {
                    $class = $ln['isFocus'] ? 'focus' : '';
                    echo "<span class='ln $class'>" . str_pad((string)$ln['ln'], 3, ' ', STR_PAD_LEFT) . "</span> ";
                    echo "<span class='$class'>" . $ln['code'] . "</span>\n";
                }
                echo "</pre>";
            }
            echo "</div>";
        }
        echo "</section>";

        // Суперглобалы
        echo "<section class='card'><h3>Request globals</h3><div class='kv'>";
        $printKV = function (string $k, $v) {
            $vv = $this->safeJson($this->sanitizeGlobals($v));
            echo "<div><strong>$k</strong></div><div><code>$vv</code></div>";
        };
        $printKV('_GET', $payload['globals']['_GET']);
        $printKV('_POST', $payload['globals']['_POST']);
        $printKV('_COOKIE', $payload['globals']['_COOKIE']);
        $printKV('_SERVER', ['REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null, 'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null, 'HTTP_ACCEPT' => $_SERVER['HTTP_ACCEPT'] ?? null, 'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? null,]);
        echo "</div></section>";

        echo "</div></body></html>";
    }

    private function safeJson($v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json !== false ? $json : '[unserializable]';
    }

    private function sanitizeGlobals(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveKeys, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeGlobals($value);
            }
        }
        return $data;
    }
}