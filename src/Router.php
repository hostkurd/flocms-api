<?php
declare(strict_types=1);

namespace FloCMS\Api;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Exceptions\MethodNotAllowedException;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @var list<array{prefix: string, middleware: list<mixed>, module: ?string, name: string}>
     */
    private array $groups = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param bool $strict throw on shadowed routes and duplicate route names
     *        instead of logging a warning (recommended in development)
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
        private bool $strict = false
    ) {
    }

    /**
     * Throw InvalidArgumentException on shadowed routes and duplicate names.
     * Without strict mode they are logged as warnings and listed by warnings().
     */
    public function strict(bool $strict = true): self
    {
        $this->strict = $strict;

        return $this;
    }

    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /** @return list<string> Registration problems found outside strict mode. */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The path of a named route:
     *
     *     $router->url('properties.show', ['slug' => 'sea-villa'], ['page' => 2]);
     *     // "/api/v1/properties/sea-villa?page=2"
     *
     * Parameters are checked against the route's constraints and URL-encoded.
     * The path includes group prefixes and is relative to the host.
     *
     * @param array<string, string|int|float> $params
     * @param array<string, mixed> $query
     * @throws InvalidArgumentException for an unknown name or missing, unknown or invalid parameters
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException('Unknown route name: ' . $name);

        $path = '';
        $used = [];
        foreach ($route->segments() as $segment) {
            if ($segment[0] === 'static') {
                $path .= '/' . $segment[1];
                continue;
            }

            $param = $segment[1];
            if (!array_key_exists($param, $params) || !is_scalar($params[$param]) || is_bool($params[$param])) {
                throw new InvalidArgumentException('Route "' . $name . '" needs parameter "' . $param . '".');
            }
            // Requests are matched before decoding, so check the encoded form.
            $value = rawurlencode((string) $params[$param]);
            $constraint = $segment[2] ?? '[^/]+';
            if ($value === '' || preg_match('~^(?:' . str_replace('~', '\~', $constraint) . ')$~D', $value) !== 1) {
                throw new InvalidArgumentException('Parameter "' . $param . '" of route "' . $name . '" does not match ' . $constraint . '.');
            }
            $path .= '/' . $value;
            $used[] = $param;
        }

        $unknown = array_diff(array_keys($params), $used);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Route "' . $name . '" has no parameter(s): ' . implode(', ', $unknown) . '.');
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return ($path === '' ? '/' : $path) . ($queryString !== '' ? '?' . $queryString : '');
    }

    /** The route registered under $name, or null. */
    public function named(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

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
            if (
                array_intersect($existing->methods(), $route->methods()) !== []
                && self::covers($existing, $route)
            ) {
                $this->report(
                    'Route ' . implode('|', $route->methods()) . ' ' . $pattern
                    . ' can never match: ' . $existing->pattern()
                    . ' is registered first and matches the same paths.'
                );
            }
        }

        $route->onName(function (Route $named, string $name): void {
            $existing = $this->named[$name] ?? null;
            if ($existing !== null && $existing !== $named) {
                $this->report('Duplicate route name "' . $name . '" (' . $existing->pattern()
                    . ' and ' . $named->pattern() . ').');

                return;
            }
            $this->named[$name] = $named;
        });
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

    /**
     * Whether every path $later matches is already matched by $earlier.
     * Routes match first-registered first, so $later would be unreachable.
     */
    private static function covers(Route $earlier, Route $later): bool
    {
        $a = $earlier->segments();
        $b = $later->segments();
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $index => $segment) {
            $other = $b[$index];
            if ($segment[0] === 'param' && $segment[2] === null) {
                continue; // an unconstrained parameter matches any one segment
            }
            if ($segment[0] === 'static' && $other[0] === 'static' && $segment[1] === $other[1]) {
                continue;
            }
            if ($segment[0] === 'param' && $other[0] === 'param' && $segment[2] === $other[2]) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function report(string $message): void
    {
        if ($this->strict) {
            throw new InvalidArgumentException($message);
        }

        $this->warnings[] = $message;
        $this->logger?->warning('API route registration: ' . $message);
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
