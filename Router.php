<?php
declare(strict_types=1);

namespace CodeX;

use Closure;
use CodeX\Http\Response;
use CodeX\Http\Stream;
use CodeX\Router\AfterMatch;
use CodeX\Router\BeforeMatch;
use CodeX\Router\Domain;
use CodeX\Router\Node;
use CodeX\Router\Route;
use InvalidArgumentException;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Router implements RequestHandlerInterface
{
    private array $globalMiddleware = [];
    private ?string $cacheFile;
    private ?EventDispatcherInterface $dispatcher;
    private array $namedRoutes = [];
    private ?Domain $domainRouter = null;

    public function __construct(?string $cacheFile = null, ?EventDispatcherInterface $dispatcher = null)
    {
        $this->cacheFile = $cacheFile;
        $this->dispatcher = $dispatcher;
    }

    public function loadRoutes(callable $defineRoutes): void
    {
        // === 1. Попытка загрузить из кэша ===
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            $data = @unserialize(file_get_contents($this->cacheFile), ['allowed_classes' => true]);
            if ($data && is_array($data) && isset($data['routes'], $data['files']) && $this->isCacheValid($data)) {
                $this->buildFromCache($data['routes']);
                return;
            }
        }

        // === 2. Сбор маршрутов ===
        $routes = [];
        $defineRoutes(function (string $domain, string $method, string $path, string|Closure $handler, bool $isFallback = false) use (&$routes): Route {
            $route = new Route($handler);
            $routes[] = ['domain' => $domain, 'method' => $method, 'path' => $path, 'route' => $route, 'is_fallback' => $isFallback,];
            return $route;
        });

        // === 3. Сбор хэшей файлов для инвалидации ===
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 100);
        $files = [];
        foreach ($trace as $frame) {
            if (isset($frame['file']) && is_file($frame['file'])) {
                $files[$frame['file']] = hash_file('sha256', $frame['file']);
            }
        }

        // === 4. Построение графа и кэширование ===
        $this->buildGraph($routes);
        if ($this->cacheFile) {
            file_put_contents($this->cacheFile, serialize(['routes' => $routes, 'files' => $files]));
        }
    }

    private function isCacheValid(array $cached): bool
    {
        foreach ($cached['files'] as $file => $hash) {
            if (!file_exists($file) || hash_file('sha256', $file) !== $hash) {
                return false;
            }
        }
        return true;
    }

    private function buildFromCache(array $routes): void
    {
        $this->buildGraph($routes);
    }

    private function buildGraph(array $routes): void
    {
        $this->domainRouter = new Domain();
        $this->namedRoutes = [];

        foreach ($routes as $item) {
            [
                'domain' => $domain,
                'method' => $method,
                'path' => $path,
                'route' => $route,
                'is_fallback' => $isFallback
            ] = $item;

            if ($isFallback) {
                $this->domainRouter->setFallback($domain, $route);
            } else {
                $segments = $path === '/' ? [] : explode('/', trim($path, '/'));
                $this->domainRouter->addRoute($domain, $method, $segments, $route);
            }

            if ($route->name !== null) {
                $this->namedRoutes[$route->name] = ['domain' => $domain, 'method' => $method, 'path' => $path, 'route' => $route,];
            }
        }
    }

    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException("Маршрут [$name] не определён.");
        }

        //$domain = $this->namedRoutes[$name]['domain'];
        $path = $this->namedRoutes[$name]['path'];

        // Если нужен полный URL с доменом — можно расширить сигнатуру метода
        return preg_replace_callback('/\{(\w+)}/', static function ($m) use ($params, $name) {
            $key = $m[1];
            if (!isset($params[$key])) {
                throw new InvalidArgumentException("Отсутствует параметр [$key] для маршрута [$name].");
            }
            return urlencode((string)$params[$key]);
        }, $path);
    }

    public function generateOpenApi(): array
    {
        $paths = [];
        foreach ($this->namedRoutes as $routeData) {
            $method = strtolower($routeData['method']);
            $path = $routeData['path'];
            $openApi = $routeData['route']->openApi;
            if (empty($openApi)) {
                continue;
            }
            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }
            $paths[$path][$method] = $openApi;
        }

        return ['openapi' => '3.1.0', 'info' => ['title' => 'API', 'version' => '1.0.0'], 'paths' => $paths,];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // === 1. Событие: BeforeRouteMatch ===
        if ($this->dispatcher !== null) {
            $event = new BeforeMatch($request);
            $this->dispatcher->dispatch($event);
            if ($event->propagationStopped) {
                return new Response(403, 'Access forbidden');
            }
            $request = $event->request;
        }

        $method = $request->getMethod();
        $params = [];
        $route = null;

        // === 2. Поиск маршрута (с учётом домена и fallback) ===
        $match = $this->domainRouter?->match($request);

        if ($match !== null) {
            [$route, $params] = $match;
            $request = $request->withAttribute('route_params', $params);
            $request = $request->withAttribute('route_name', $route->name ?? null);
        }

        // === 3. Событие: AfterRouteMatch ===
        $this->dispatcher?->dispatch(new AfterMatch($request, $route, $params));

        // === 4. Обработка OPTIONS ===
        if ($method === 'OPTIONS') {
            $allowed = $this->domainRouter?->getAllMethodsForPath($request) ?: [];
            if (empty($allowed)) {
                return new Response(404, 'Not Found');
            }
            return new Response(204)->withHeader('Allow', implode(', ', $allowed));
        }

        // === 5. Выполнение маршрута ===
        if ($route !== null) {
            $handler = $this->resolveHandler($route->handler);
            $middlewareStack = array_merge($this->globalMiddleware, $route->middleware);

            $next = $handler;
            foreach (array_reverse($middlewareStack) as $middleware) {
                $next = new class($middleware, $next) implements RequestHandlerInterface {
                    public function __construct(private $middleware, private RequestHandlerInterface $next)
                    {
                    }

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        if ($this->middleware instanceof MiddlewareInterface) {
                            return $this->middleware->process($request, $this->next);
                        }
                        if (is_callable($this->middleware)) {
                            return ($this->middleware)($request, $this->next);
                        }
                        throw new LogicException('Invalid middleware type');
                    }
                };
            }

            $response = $next->handle($request);

            // === 6. Обработка HEAD: удаляем тело ===
            if ($method === 'HEAD') {
                $emptyStream = new Stream('php://memory', 'r');
                $response = $response->withBody($emptyStream)->withoutHeader('Content-Length')->withoutHeader('Content-Type');
            }

            return $response;
        }

        // === 7. 405 или 404 ===
        $allowed = $this->domainRouter?->getAllMethodsForPath($request) ?: [];
        if (!empty($allowed)) {
            return new Response(405, 'Method Not Allowed')->withHeader('Allow', implode(', ', $allowed));
        }

        return new Response(404, 'Not Found');
    }

    private function resolveHandler(string|Closure $handler): RequestHandlerInterface
    {
        if ($handler instanceof Closure) {
            return new readonly class($handler) implements RequestHandlerInterface {
                public function __construct(private Closure $closure)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $result = ($this->closure)($request);
                    if ($result instanceof ResponseInterface) {
                        return $result;
                    }
                    if (is_array($result)) {
                        return new Response()->withJson($result);
                    }
                    if (is_string($result)) {
                        return new Response()->withHtml($result);
                    }
                    throw new LogicException('Handler must return Response, array, or string');
                }
            };
        }
        return new $handler();
    }

    public function middleware(array $middleware): self
    {
        $this->globalMiddleware = $middleware;
        return $this;
    }
    public function setCacheFile(?string $path): void
    {
        $this->cacheFile = $path;
    }
}