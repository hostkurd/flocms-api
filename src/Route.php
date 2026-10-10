<?php
declare(strict_types=1);

namespace FloCMS\Api;

use InvalidArgumentException;

final class Route
{
    /** @var list<string> */
    private array $methods;

    /** @var list<mixed> */
    private array $middleware = [];

    private ?string $routeName = null;
    private string $namePrefix = '';
    private ?string $moduleId = null;
    private string $regex;

    /** @var list<string> */
    private array $parameterNames = [];

    /** @param list<string> $methods */
    public function __construct(
        array $methods,
        private readonly string $pattern,
        private readonly mixed $handler
    ) {
        if ($handler === null) {
            throw new InvalidArgumentException('A route handler is required.');
        }

        $methods = array_values(array_unique(array_map('strtoupper', $methods)));
        if ($methods === []) {
            throw new InvalidArgumentException('A route must allow at least one HTTP method.');
        }
        foreach ($methods as $method) {
            if (!preg_match('/^[A-Z]+$/', $method)) {
                throw new InvalidArgumentException('Invalid HTTP method: ' . $method);
            }
        }

        $this->methods = $methods;
        [$this->regex, $this->parameterNames] = $this->compilePattern($pattern);
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    public function name(?string $name = null): self|string|null
    {
        if ($name === null) {
            return $this->routeName;
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $name)) {
            throw new InvalidArgumentException('Invalid route name: ' . $name);
        }
        $this->routeName = $this->namePrefix . $name;

        return $this;
    }

    public function namePrefix(string $prefix): self
    {
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_.:-]+$/', $prefix)) {
            throw new InvalidArgumentException('Invalid route name prefix: ' . $prefix);
        }
        $this->namePrefix = $prefix;

        return $this;
    }

    public function module(?string $moduleId = null): self|string|null
    {
        if ($moduleId === null) {
            return $this->moduleId;
        }
        $moduleId = strtolower(trim($moduleId));
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $moduleId)) {
            throw new InvalidArgumentException('Invalid route module id: ' . $moduleId);
        }
        $this->moduleId = $moduleId;

        return $this;
    }

    public function middleware(mixed ...$middleware): self
    {
        foreach ($middleware as $entry) {
            if (is_array($entry) && array_is_list($entry)) {
                array_push($this->middleware, ...$entry);
            } else {
                $this->middleware[] = $entry;
            }
        }

        return $this;
    }

    /** @return list<mixed> */
    public function middlewareStack(): array
    {
        return $this->middleware;
    }

    /** @return array<string, string>|null */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($this->parameterNames as $name) {
            $params[$name] = rawurldecode((string) ($matches[$name] ?? ''));
        }

        return $params;
    }

    /** @return array{0: string, 1: list<string>} */
    private function compilePattern(string $pattern): array
    {
        $normalized = Router::normalizePath($pattern);
        if ($normalized === '/') {
            return ['~^/$~D', []];
        }

        $parts = explode('/', ltrim($normalized, '/'));
        $regex = '';
        $names = [];

        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::(.+))?\}$/', $part, $match)) {
                $name = $match[1];
                if (in_array($name, $names, true)) {
                    throw new InvalidArgumentException('Duplicate route parameter: ' . $name);
                }

                $constraint = $match[2] ?? null;
                if ($constraint === null) {
                    $constraint = '[^/]+';
                } elseif ($constraint === '' || str_contains($constraint, '/')) {
                    throw new InvalidArgumentException(
                        'Route parameter constraints must match one path segment: ' . $name
                    );
                }
                $constraint = str_replace('~', '\\~', $constraint);
                $regex .= '/(?P<' . $name . '>' . $constraint . ')';
                $names[] = $name;
                continue;
            }

            if (str_contains($part, '{') || str_contains($part, '}')) {
                throw new InvalidArgumentException('Invalid route segment: ' . $part);
            }

            $regex .= '/' . preg_quote($part, '~');
        }

        return ['~^' . $regex . '/?$~D', $names];
    }
}
