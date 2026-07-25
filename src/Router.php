<?php
declare(strict_types=1);

namespace FloCMS\Api;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Exceptions\MethodNotAllowedException;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @var list<array{prefix: string, middleware: list<mixed>, module: ?string, name: string}>
     */
    private array $groups = [];

    public function get(string $path, mixed $handler): Route
    {
        return $this->map(['GET'], $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->map(['POST'], $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->map(['PUT'], $path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->map(['PATCH'], $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->map(['DELETE'], $path, $handler);
    }

    public function head(string $path, mixed $handler): Route
    {
        return $this->map(['HEAD'], $path, $handler);
    }

    public function options(string $path, mixed $handler): Route
    {
        return $this->map(['OPTIONS'], $path, $handler);
    }

    /** @param list<string> $methods */
    public function map(array $methods, string $path, mixed $handler): Route
    {
        $group = $this->currentGroup();
        $pattern = self::normalizePath($group['prefix'] . '/' . ltrim($path, '/'));
        $route = new Route($methods, $pattern, $handler);

        if ($group['middleware'] !== []) {
            $route->middleware($group['middleware']);
        }
        if ($group['module'] !== null) {
            $route->module($group['module']);
        }
        if ($group['name'] !== '') {
            $route->namePrefix($group['name']);
        }

        foreach ($this->routes as $existing) {
            if (
                $existing->pattern() === $route->pattern()
                && array_intersect($existing->methods(), $route->methods()) !== []
            ) {
                throw new InvalidArgumentException(
                    'Duplicate route for ' . implode('|', $route->methods()) . ' ' . $pattern
                );
            }
        }

        $this->routes[] = $route;

        return $route;
    }

    /**
     * @param callable(self): void $routes
     * @param list<mixed> $middleware
     */
    public function group(
        string $prefix,
        callable $routes,
        array $middleware = [],
        ?string $module = null,
        string $namePrefix = ''
    ): void {
        $parent = $this->currentGroup();
        $this->groups[] = [
            'prefix' => self::normalizePath($parent['prefix'] . '/' . ltrim($prefix, '/')),
            'middleware' => array_merge($parent['middleware'], $middleware),
            'module' => $module ?? $parent['module'],
            'name' => $parent['name'] . $namePrefix,
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groups);
        }
    }

    public function match(Request $request): RouteMatch
    {
        $path = self::normalizePath($request->path());
        $method = $request->method();
        $allowed = [];
        $headFallback = null;

        foreach ($this->routes as $route) {
            $params = $route->matchPath($path);
            if ($params === null) {
                continue;
            }

            $methods = $route->methods();
            array_push($allowed, ...$methods);

            if (in_array($method, $methods, true)) {
                return new RouteMatch($route, $params);
            }
            if ($method === 'HEAD' && in_array('GET', $methods, true) && $headFallback === null) {
                $headFallback = new RouteMatch($route, $params);
            }
        }

        if ($headFallback instanceof RouteMatch) {
            return $headFallback;
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $allowed[] = 'OPTIONS';
            $allowed = array_values(array_unique($allowed));
            sort($allowed, SORT_STRING);

            throw new MethodNotAllowedException($allowed);
        }

        throw new ApiException('Endpoint not found.', 404);
    }

    /** @return list<string> */
    public function allowedMethods(string $path): array
    {
        $allowed = [];
        $path = self::normalizePath($path);

        foreach ($this->routes as $route) {
            if ($route->matchPath($path) !== null) {
                array_push($allowed, ...$route->methods());
            }
        }
        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }
        if ($allowed !== []) {
            $allowed[] = 'OPTIONS';
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed, SORT_STRING);

        return $allowed;
    }

    public function hasExplicitRoute(string $method, string $path): bool
    {
        $method = strtoupper(trim($method));
        $path = self::normalizePath($path);

        foreach ($this->routes as $route) {
            if (
                in_array($method, $route->methods(), true)
                && $route->matchPath($path) !== null
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public static function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH);
        $path = is_string($path) ? preg_replace('#/+#', '/', $path) : '/';
        $path = '/' . trim((string) $path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @return array{prefix: string, middleware: list<mixed>, module: ?string, name: string} */
    private function currentGroup(): array
    {
        return $this->groups[array_key_last($this->groups)] ?? [
            'prefix' => '',
            'middleware' => [],
            'module' => null,
            'name' => '',
        ];
    }
}
