<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use Closure;
use InvalidArgumentException;

/**
 * Short names for middleware, usable as strings in middleware lists:
 *
 *     $aliases->register('throttle', fn (string $name) => $limiters->middleware($name));
 *     $router->post('/v1/inquiries', $handler)->middleware('throttle:forms');
 *
 * Arguments after the colon are split on commas and passed to the factory.
 */
final class MiddlewareAliases
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /** @param array<string, callable> $factories */
    public function __construct(array $factories = [])
    {
        foreach ($factories as $name => $factory) {
            $this->register($name, $factory);
        }
    }

    /** @param callable(string ...): mixed $factory returns a middleware instance or callable */
    public function register(string $name, callable $factory): self
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name)) {
            throw new InvalidArgumentException('Invalid middleware alias: ' . $name);
        }
        $this->factories[$name] = Closure::fromCallable($factory);

        return $this;
    }

    public function has(string $entry): bool
    {
        return isset($this->factories[explode(':', $entry, 2)[0]]);
    }

    public function resolve(string $entry): mixed
    {
        [$name, $arguments] = array_pad(explode(':', $entry, 2), 2, null);
        $factory = $this->factories[$name] ?? null;
        if ($factory === null) {
            throw new InvalidArgumentException('Unknown middleware alias: ' . $name);
        }

        $arguments = $arguments === null || $arguments === ''
            ? []
            : array_map('trim', explode(',', $arguments));

        return $factory(...$arguments);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->factories);
    }
}
