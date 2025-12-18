<?php
declare(strict_types=1);

namespace CodeX;

use CodeX\Container\ContainerException;
use CodeX\Container\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

final class Container implements ContainerInterface
{
    private array $definitions = [];
    private array $resolved = [];
    private array $aliases = [];
    private array $factories = [];

    private array $beforeHooks = [];
    private array $afterHooks = [];
    private array $globalBeforeHooks = [];
    private array $globalAfterHooks = [];
    private array $methodHooks = [];

    private array $buildingStack = [];
    private array $reflectionCache = [];

    /**
     * Регистрация сервиса
     */
    public function set(string $id, callable|object|string $concrete, bool $shared = true): void
    {
        $this->definitions[$id] = $concrete;

        if (!$shared) {
            $this->factories[$id] = true;
        }

        // Удаляем из кэша, если переопределяем
        unset($this->resolved[$id], $this->reflectionCache[$id]);
    }

    /**
     * Регистрация алиаса
     * @throws ContainerException
     */
    public function alias(string $alias, string $target): void
    {
        if ($alias === $target) {
            throw new ContainerException("Alias cannot reference itself: {$alias}");
        }

        $this->aliases[$alias] = $target;
    }

    /**
     * Вызов метода с инъекцией зависимостей
     * @param callable|array $callable
     * @param array $parameters
     * @return mixed
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function call(callable|array $callable, array $parameters = []): mixed
    {
        if (is_array($callable) && count($callable) === 2) {
            [$class, $method] = $callable;

            if (is_string($class)) {
                $class = $this->get($class);
            }

            return $this->callMethod($class, $method, $parameters);
        }

        if (is_callable($callable)) {
            return $this->callFunction($callable, $parameters);
        }

        throw new ContainerException('Invalid callable provided');
    }

    /**
     * Получение сервиса с поддержкой PSR-11
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function get(string $id): object
    {
        // Проверка циклических зависимостей
        if (in_array($id, $this->buildingStack, true)) {
            throw new ContainerException(sprintf('Circular dependency detected: %s', implode(' -> ', $this->buildingStack)));
        }

        $originalId = $id;
        $id = $this->resolveAlias($id);

        // Возвращаем уже созданный экземпляр (синглтон)
        if (isset($this->resolved[$id]) && !isset($this->factories[$id])) {
            return $this->resolved[$id];
        }

        $this->buildingStack[] = $originalId;

        try {
            // Выполняем before-хуки
            $this->executeBeforeHooks($id);

            // Создаем объект
            $object = $this->build($id);

            // Сохраняем для синглтонов
            if (!isset($this->factories[$id])) {
                $this->resolved[$id] = $object;
            }

            // Выполняем after-хуки
            return $this->executeAfterHooks($object, $id);
        } finally {
            array_pop($this->buildingStack);
        }
    }

    private function executeBeforeHooks(string $id): void
    {
        foreach ($this->globalBeforeHooks as $hook) {
            $hook($id, $this);
        }

        foreach ($this->beforeHooks[$id] ?? [] as $hook) {
            $hook($id, $this);
        }
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function build(string $id): object
    {
        if (!isset($this->definitions[$id])) {
            return $this->autowire($id);
        }

        $definition = $this->definitions[$id];

        if (is_callable($definition)) {
            return $definition($this);
        }

        if (is_object($definition)) {
            return $definition;
        }

        if (is_string($definition) && class_exists($definition)) {
            return $this->autowire($definition);
        }

        throw new ContainerException(sprintf('Invalid definition for service "%s"', $id));
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException(sprintf('Class "%s" does not exist', $class));
        }

        $reflection = $this->getReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Class "%s" is not instantiable', $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $parameters = $this->resolveParameters($constructor->getParameters(), $class, '__construct');

        return $reflection->newInstanceArgs($parameters);
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ContainerException
     * @throws NotFoundException|ReflectionException
     */
    private function resolveParameters(array $parameters, string $class, string $method): array
    {
        $resolved = [];

        foreach ($parameters as $parameter) {
            $resolved[] = $this->resolveParameter($parameter, $class, $method);
        }

        return $resolved;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ContainerException
     * @throws NotFoundException|ReflectionException
     */
    private function resolveParameter(ReflectionParameter $parameter, string $class, string $method): mixed
    {
        $type = $parameter->getType();

        // Union-типы
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin()) {
                    $name = $unionType->getName();
                    if ($this->has($name)) {
                        return $this->get($name);
                    }
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($parameter->allowsNull()) {
                return null;
            }

            throw new ContainerException(sprintf('Cannot resolve parameter "%s" of type "%s" for %s::%s', $parameter->getName(), $type, $class, $method));
        }

        // Named types (классы/интерфейсы)
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            if (!$this->has($name)) {
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }

                if ($parameter->allowsNull()) {
                    return null;
                }

                throw new NotFoundException(sprintf('Dependency "%s" not found for %s::%s', $name, $class, $method));
            }

            return $this->get($name);
        }

        // Built-in типы или отсутствие типа
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf('Cannot resolve parameter "%s" for %s::%s', $parameter->getName(), $class, $method));
    }

    /**
     * @throws ContainerException
     */
    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        return isset($this->definitions[$id]) || (class_exists($id) && $this->isInstantiable($id));
    }

    /**
     * @throws ContainerException
     */
    private function resolveAlias(string $id): string
    {
        $resolved = $id;
        $visited = [];

        while (isset($this->aliases[$resolved])) {
            if (isset($visited[$resolved])) {
                throw new ContainerException(sprintf('Circular alias detected: %s', implode(' -> ', array_keys($visited))));
            }

            $visited[$resolved] = true;
            $resolved = $this->aliases[$resolved];
        }

        return $resolved;
    }

    // Приватные методы

    private function isInstantiable(string $class): bool
    {
        try {
            return $this->getReflectionClass($class)->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * @throws ReflectionException
     */
    private function getReflectionClass(string $class): ReflectionClass
    {
        if (!isset($this->reflectionCache[$class])) {
            $this->reflectionCache[$class] = new ReflectionClass($class);
        }

        return $this->reflectionCache[$class];
    }

    private function executeAfterHooks(object $object, string $id): object
    {
        foreach ($this->afterHooks[$id] ?? [] as $hook) {
            $object = $hook($object, $id, $this) ?? $object;
        }

        foreach ($this->globalAfterHooks as $hook) {
            $object = $hook($object, $id, $this) ?? $object;
        }

        return $object;
    }

    /**
     * @param object $object
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function callMethod(object $object, string $method, array $parameters): mixed
    {
        $class = get_class($object);
        $key = $this->getMethodKey($class, $method);

        // Before method hooks
        foreach ($this->methodHooks['before'][$key] ?? [] as $hook) {
            $hook($object, $method, $parameters, $this);
        }

        $reflectionMethod = $this->getReflectionMethod($class, $method);
        $methodParameters = $this->resolveParameters($reflectionMethod->getParameters(), $class, $method);

        // Объединяем с переданными параметрами
        $methodParameters = $this->mergeParameters($methodParameters, $parameters, $reflectionMethod);

        $result = $reflectionMethod->invokeArgs($object, $methodParameters);

        // After method hooks
        foreach ($this->methodHooks['after'][$key] ?? [] as $hook) {
            $result = $hook($result, $object, $method, $this);
        }

        return $result;
    }

    private function getMethodKey(string $class, string $method): string
    {
        return "{$class}::{$method}";
    }

    /**
     * @throws ReflectionException
     */
    private function getReflectionMethod(string $class, string $method): ReflectionMethod
    {
        $key = "{$class}::{$method}";

        if (!isset($this->reflectionCache[$key])) {
            $this->reflectionCache[$key] = new ReflectionMethod($class, $method);
        }

        return $this->reflectionCache[$key];
    }

    private function mergeParameters(array $resolved, array $provided, $reflection): array
    {
        foreach ($reflection->getParameters() as $index => $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $provided)) {
                $resolved[$index] = $provided[$name];
            } elseif (array_key_exists($index, $provided)) {
                $resolved[$index] = $provided[$index];
            }
        }

        return $resolved;
    }

    /**
     * @param callable $function
     * @param array $parameters
     * @return mixed
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function callFunction(callable $function, array $parameters): mixed
    {
        $reflection = new ReflectionFunction($function);
        $functionParameters = $this->resolveParameters($reflection->getParameters(), '', $reflection->getName());

        $functionParameters = $this->mergeParameters($functionParameters, $parameters, $reflection);

        return $reflection->invokeArgs($functionParameters);
    }

    /**
     * Регистрация хуков
     */
    public function before(string $id, callable $hook): void
    {
        $this->beforeHooks[$id][] = $hook;
    }

    public function after(string $id, callable $hook): void
    {
        $this->afterHooks[$id][] = $hook;
    }

    public function beforeAny(callable $hook): void
    {
        $this->globalBeforeHooks[] = $hook;
    }

    public function afterAny(callable $hook): void
    {
        $this->globalAfterHooks[] = $hook;
    }

    public function beforeMethod(string $class, string $method, callable $hook): void
    {
        $key = $this->getMethodKey($class, $method);
        $this->methodHooks['before'][$key][] = $hook;
    }

    public function afterMethod(string $class, string $method, callable $hook): void
    {
        $key = $this->getMethodKey($class, $method);
        $this->methodHooks['after'][$key][] = $hook;
    }
}