<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use Closure;
use FloCMS\Api\Database\Connection;
use FloCMS\Api\RouteLoader;
use FloCMS\Api\Router;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\CommandFailedException;
use FloCMS\CLI\Project;
use FloCMS\Core\Support\Container;
use PDO;

/**
 * Base class of the api:* commands, run through flocms-cli (`php flo api:...`).
 *
 * The database comes from FLO_API_DSN (plus FLO_API_DB_USER / FLO_API_DB_PASS)
 * when set, otherwise from the project's .env like every flo command
 * (DB_HOST, DB_PORT, DB_NAME, DB_USERNAME, DB_PASSWORD; DB_TYPE=sqlite).
 */
abstract class ApiCommand extends Command
{
    private ?Connection $connection = null;

    /** Use this connection instead of FLO_API_DSN / .env (tests, the 1.1 Application). */
    public function useConnection(?Connection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    protected function connection(): Connection
    {
        return $this->connection ??= self::connectionFor($this->project());
    }

    /** FLO_API_DSN when set, otherwise the project's database from .env. */
    public static function connectionFor(Project $project): Connection
    {
        $dsn = getenv('FLO_API_DSN');
        if (is_string($dsn) && $dsn !== '') {
            return new Connection(new PDO($dsn, getenv('FLO_API_DB_USER') ?: null, getenv('FLO_API_DB_PASS') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
        }
        if (!$project->hasEnvFile() && (string) $project->env('DB_DSN', '') === '') {
            throw new CommandFailedException('No .env file in ' . $project->root() . ' (or set FLO_API_DSN).');
        }

        return new Connection($project->database()->pdo());
    }

    /** A project-relative path, or an absolute path as given. */
    protected function path(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : $this->project()->root() . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Columns separated by two spaces, as in flocms-api 1.1 (stable for
     * scripts that parse the output).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     */
    protected function plainTable(array $rows, array $columns): void
    {
        foreach (self::plainTableLines($rows, $columns) as $line) {
            $this->output->writeln(\FloCMS\CLI\Console\Output\Formatter::escape($line));
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @return list<string>
     */
    public static function plainTableLines(array $rows, array $columns): array
    {
        if ($rows === []) {
            return ['(none)'];
        }
        $cells = [$columns];
        foreach ($rows as $row) {
            $cells[] = array_map(static function (string $column) use ($row): string {
                $value = $row[$column] ?? null;

                return match (true) {
                    $value === null => '-',
                    is_array($value) => implode(',', $value),
                    default => (string) $value,
                };
            }, $columns);
        }
        $widths = [];
        foreach ($cells as $line) {
            foreach ($line as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell));
            }
        }
        $lines = [];
        foreach ($cells as $line) {
            $padded = [];
            foreach ($line as $index => $cell) {
                $padded[] = $cell . str_repeat(' ', $widths[$index] - mb_strlen($cell));
            }
            $lines[] = rtrim(implode('  ', $padded));
        }

        return $lines;
    }

    /** A trimmed option value, or null when absent or empty. */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === true) {
            $this->invalid('--' . $name . ' needs a value.');
        }

        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    protected function intOption(string $name): ?int
    {
        $value = $this->stringOption($name);
        if ($value === null) {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            $this->invalid('--' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /** @return list<string> */
    protected function listOption(string $name): array
    {
        $value = $this->stringOption($name);

        return $value === null ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    protected function idArgument(string $name): int
    {
        $id = (string) $this->argument($name);
        if (!ctype_digit($id) || (int) $id < 1) {
            $this->invalid('A positive numeric id is required.');
        }

        return (int) $id;
    }

    /** Load the application's routes the way public/api.php does. */
    public static function loadRouter(string $projectRoot, string $routesFile, string $prefix): Router
    {
        if (!defined('ROOT')) {
            define('ROOT', rtrim($projectRoot, '/\\'));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }
        $router = new Router();
        $router->group($prefix, static function (Router $router) use ($routesFile): void {
            (new RouteLoader($router, new Container()))->load($routesFile);
        });

        return $router;
    }

    public static function describeMiddleware(mixed $middleware): string
    {
        return match (true) {
            is_string($middleware) => $middleware,
            $middleware instanceof Closure => 'closure',
            is_object($middleware) => (new \ReflectionClass($middleware))->getShortName(),
            default => get_debug_type($middleware),
        };
    }
}
