<?php
declare(strict_types=1);

namespace CodeX;

use ErrorException;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Throwable;

class Application
{

    private static ?Application $instance = null;
    public readonly string $dir;
    public readonly ContainerInterface $container;
    public array $config = [];
    private string $fallback = '<h2>Серверная ошибка</h2>';
    private string $fallbackCreate = '<h2>Критическая ошибка приложения.</h2>';
    private array $providers = [];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(?string $dir = null)
    {
        error_reporting(-1);
        ini_set('display_errors', 'on');
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
        $this->dir = rtrim($dir ?? __DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->loadConfiguration();
        $this->container = $this->initContainer();
        $this->registerProviders();
        self::$instance = $this;
    }

    private function loadConfiguration(): void
    {
        $coreConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'core.php';
        if (!file_exists($coreConfigPath)) {
            throw new RuntimeException('Файл конфигурации ядра не найден: ' . $coreConfigPath);
        }

        $config = include $coreConfigPath;
        if ($config === false) {
            throw new RuntimeException('Ошибка при загрузке файла конфигурации (возможно, синтаксическая ошибка): ' . $coreConfigPath);
        }
        if (!is_array($config)) {
            throw new RuntimeException('Файл конфигурации ядра не является массивом: ' . $coreConfigPath);
        }

        $appConfigPath = $this->dir . 'config' . DIRECTORY_SEPARATOR . 'core.php';
        if (file_exists($appConfigPath)) {
            $appConfig = include $appConfigPath;
            if (!is_array($appConfig)) {
                throw new RuntimeException('Файл конфигурации приложения не является массивом: ' . $appConfigPath);
            }
            $this->config = array_replace_recursive($config, $appConfig);
            $this->config['providers'] = array_merge($config['providers'] ?? [], $appConfig['providers'] ?? []);
        } else {
            $this->config = $config;
        }
    }

    private function initContainer(): ContainerInterface
    {
        $containerClass = $this->config['container']['class'] ?? Container::class;
        if (!class_exists($containerClass)) {
            throw new RuntimeException('Класс контейнера не найден: ' . $containerClass);
        }
        $container = new $containerClass();
        if (!$container instanceof ContainerInterface) {
            throw new RuntimeException('Контейнер ' . $containerClass . ' не реализует ' . ContainerInterface::class);
        }
        $container->set(__CLASS__, $this);
        return $container;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function registerProviders(): void
    {
        foreach ($this->config['providers'] ?? [] as $providerClass) {
            $provider = $this->container->get($providerClass);
            if (!method_exists($provider, 'register')) {
                throw new RuntimeException('Провайдер ' . $providerClass . ' должен иметь метод register()');
            }
            $provider->register();
            $this->providers[] = $provider;
        }
    }

    public static function getInstance(): Application
    {
        if (self::$instance === null) {
            throw new RuntimeException('Приложение не инициализировано. Вызовите new Application() перед использованием getInstance().');
        }
        return self::$instance;
    }

    /**
     * @throws ErrorException
     */
    public function handleError(int $severity, string $message, string $file, int $line): void
    {
        if (error_reporting() & $severity) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
    }

    /**
     * @throws JsonException
     */
    public function handleShutdown(): void
    {
        foreach ($this->config['shutdown'] ?? [] as $callback) {
            if (is_array($callback) && count($callback) === 2) {
                [$class, $method] = $callback;
                if (class_exists($class) && method_exists($class, $method)) {
                    call_user_func([$class, $method]);
                } else {
                    error_log(sprintf('[ЗАВЕРШЕНИЕ РАБОТЫ] Недопустимая функция обратного вызова %s::%s - класс или метод не существует', $class, $method));
                }
            } else {
                try {
                    $encoded = json_encode($callback, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $encoded = '[Ошибка кодирования]';
                }
                error_log(sprintf('[ЗАВЕРШЕНИЕ РАБОТЫ] Недопустимый формат обратного вызова: %s. Ожидался ["Класс", "метод"]', $encoded));
            }
        }
        if ($error = error_get_last()) {
            $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            try {
                $this->handleException($exception);
            } catch (Throwable $e) {
                $this->logError($e);
                http_response_code(500);
                echo $this->fallbackCreate;
            }
        }
    }

    #[NoReturn]
    public function handleException(Throwable $e): void
    {
        $this->logError($e);
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(500);
        echo $this->fallback;
        exit(1);
    }

    private function logError(Throwable $e): void
    {
        $logDir = $this->dir . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;

        // Создаём директорию с обработкой ошибок
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            error_log(sprintf('КРИТИЧЕСКАЯ ОШИБКА: Не удается создать каталог логов %s', $logDir));
            return;
        }

        $logPath = $logDir . 'app-' . date('Y-m-d') . '.log';

        $context = ['time' => date('Y-m-d H:i:s'),
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'server' => $_SERVER['REQUEST_URI'] ?? 'CLI'
        ];

        try {
            $logEntry = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (JsonException) {
            // Fallback при ошибке кодирования JSON
            $fallbackEntry = sprintf("[%s] %s: %s in %s:%d\n%s\n", $context['time'], $context['type'], $context['message'], $context['file'], $context['line'], $context['trace']);
            file_put_contents($logPath, $fallbackEntry, FILE_APPEND | LOCK_EX);
        } catch (Throwable $writeException) {
            error_log(sprintf('CRITICAL: Cannot write to log file %s: %s', $logPath, $writeException->getMessage()));
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }
        foreach ($this->config['middleware'] ?? [] as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);
            if (!method_exists($middleware, 'handle')) {
                throw new RuntimeException(sprintf('Промежуточное ПО %s должно иметь метод "handle".', $middlewareClass));
            }
            $middleware->handle();
        }
    }
}