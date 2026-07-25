<?php
declare(strict_types=1);

namespace FloCMS\Api;

use FloCMS\Core\Modules\ModuleManager;
use FloCMS\Core\Modules\ModuleRegistry;
use FloCMS\Core\Support\ContainerInterface;
use ReflectionFunction;
use RuntimeException;

final class RouteLoader
{
    public function __construct(
        private readonly Router $router,
        private readonly ContainerInterface $container
    ) {
    }

    public function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('API route file is missing: ' . $path);
        }

        $definition = require $path;
        $this->register($definition, $path);
    }

    public function loadModules(
        ModuleRegistry $registry,
        ModuleManager $manager,
        string $channel = 'api',
        bool $enabledOnly = true
    ): void {
        foreach ($registry->installationOrder() as $module) {
            $relativePath = $module->routes[$channel] ?? null;
            if ($relativePath === null || ($enabledOnly && !$manager->isEnabled($module->id))) {
                continue;
            }

            $path = $module->resolvePath($relativePath);
            $definition = require $path;
            $this->router->group(
                '',
                function (Router $_router) use ($definition, $path): void {
                    $this->register($definition, $path);
                },
                module: $module->id
            );
        }
    }

    private function register(mixed $definition, string $source): void
    {
        if (is_callable($definition)) {
            $reflection = new ReflectionFunction($definition(...));
            $arguments = [$this->router, $this->container];
            $definition(...array_slice($arguments, 0, $reflection->getNumberOfParameters()));

            return;
        }

        if (!is_array($definition) || !array_is_list($definition)) {
            throw new RuntimeException(
                'Route file must return a registration callable or a route-definition list: ' . $source
            );
        }

        foreach ($definition as $index => $route) {
            if (!is_array($route)) {
                throw new RuntimeException('Invalid route definition #' . $index . ' in ' . $source);
            }

            $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
            $methods = is_array($methods) ? array_values($methods) : [(string) $methods];
            $registered = $this->router->map(
                array_map('strval', $methods),
                (string) ($route['path'] ?? ''),
                $route['handler'] ?? null
            );

            if (isset($route['name'])) {
                $registered->name((string) $route['name']);
            }
            if (isset($route['module'])) {
                $registered->module((string) $route['module']);
            }
            if (is_array($route['middleware'] ?? null)) {
                $registered->middleware($route['middleware']);
            }
        }
    }
}
