<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class RoutesCommand extends ApiCommand
{
    protected string $name = 'api:routes';

    protected string $description = 'List API routes with their names and middleware (all routes: php flo route:list)';

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('routes', null, Option::VALUE_REQUIRED, 'The routes file', 'api/routes.php')
            ->option('prefix', null, Option::VALUE_REQUIRED, 'The URL prefix', '/api');
    }

    protected function handle(): int
    {
        $router = self::loadRouter($this->project()->root(), $this->routesFile(), $this->stringOption('prefix') ?? '/api');
        $rows = [];
        foreach ($router->routes() as $route) {
            $rows[] = [
                'method' => implode('|', $route->methods()),
                'path' => $route->pattern(),
                'name' => $route->name(),
                'middleware' => array_map([self::class, 'describeMiddleware'], $route->middlewareStack()),
            ];
        }
        $this->plainTable($rows, ['method', 'path', 'name', 'middleware']);

        return self::SUCCESS;
    }
}
