<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\CLI\Project;
use FloCMS\CLI\Routing\RouteProvider;

/**
 * Adds api/routes.php (served under /api by public/api.php) to
 * `php flo route:list`.
 */
final class ApiRoutes implements RouteProvider
{
    public function source(): string
    {
        return 'api';
    }

    public function routes(Project $project): iterable
    {
        $file = $project->path('api/routes.php');
        if (!is_file($file)) {
            return;
        }
        foreach (ApiCommand::loadRouter($project->root(), $file, '/api')->routes() as $route) {
            $handler = $route->handler();
            yield [
                'method' => implode('|', $route->methods()),
                'uri' => $route->pattern(),
                'name' => $route->name(),
                'action' => match (true) {
                    is_array($handler) && count($handler) === 2 => (is_object($handler[0]) ? (new \ReflectionClass($handler[0]))->getShortName() : self::short((string) $handler[0])) . '@' . $handler[1],
                    is_string($handler) => self::short($handler),
                    $handler instanceof \Closure => 'closure',
                    is_object($handler) => (new \ReflectionClass($handler))->getShortName(),
                    default => get_debug_type($handler),
                },
                'middleware' => array_map([ApiCommand::class, 'describeMiddleware'], $route->middlewareStack()),
            ];
        }
    }

    private static function short(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

}
