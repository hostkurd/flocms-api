<?php
declare(strict_types=1);

namespace FloCMS\Api\Console;

use Closure;
use FloCMS\Api\Auth\ApiKeyRepository;
use FloCMS\Api\Auth\TokenRepository;
use FloCMS\Api\Database\Connection;
use FloCMS\Api\Database\SchemaInstaller;
use FloCMS\Api\Idempotency\DatabaseIdempotencyStore;
use FloCMS\Api\Idempotency\FileIdempotencyStore;
use FloCMS\Api\OpenApi\OpenApiGenerator;
use FloCMS\Api\RateLimit\FileRateLimiter;
use FloCMS\Api\RouteLoader;
use FloCMS\Api\Router;
use FloCMS\Core\Support\Container;
use FloCMS\Core\Env;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * `flo api:*` commands. Run through the skeleton's `php flo api:...`, or
 * directly with `vendor/bin/flo-api ...` from the project root.
 *
 * The database comes from the project's .env (DB_HOST, DB_PORT, DB_NAME,
 * DB_USERNAME, DB_PASSWORD, DB_CHARSET), or from FLO_API_DSN (plus
 * FLO_API_DB_USER / FLO_API_DB_PASS) when set, e.g. for SQLite.
 */
final class Application
{
    /** @var array<string, array{0: string, 1: string, 2: Closure(list<string>, array<string, string|true>): int}> */
    private array $commands = [];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly string $projectRoot,
        private ?Connection $connection = null,
        $stdout = null,
        $stderr = null
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->registerDefaults();
    }

    /**
     * @param string $usage e.g. 'api:key:create <name> [--scopes=a,b]'
     * @param Closure(list<string>, array<string, string|true>): int $handler
     */
    public function register(string $name, string $usage, string $description, Closure $handler): self
    {
        $this->commands[$name] = [$usage, $description, $handler];

        return $this;
    }

    /** @param list<string> $arguments the command line without the script name */
    public function run(array $arguments): int
    {
        $name = array_shift($arguments) ?? 'list';
        if (in_array($name, ['list', 'api:list', 'help', '--help', '-h'], true)) {
            $this->printHelp();

            return 0;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $this->error('Unknown command "' . $name . '". Run "flo api:list" (or "list") for the API commands.');

            return 1;
        }

        [$positional, $options] = self::parse($arguments);
        try {
            return ($command[2])($positional, $options);
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());
            $this->error('Usage: ' . $command[0]);

            return 1;
        } catch (Throwable $error) {
            $this->error(get_class($error) . ': ' . $error->getMessage());

            return 1;
        }
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $text . PHP_EOL);
    }

    public function connection(): Connection
    {
        return $this->connection ??= new Connection($this->connectFromEnvironment());
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * @param list<string> $arguments
     * @return array{0: list<string>, 1: array<string, string|true>}
     */
    public static function parse(array $arguments): array
    {
        $positional = [];
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([A-Za-z0-9-]+)(?:=(.*))?$/s', $argument, $matches) === 1) {
                $options[$matches[1]] = array_key_exists(2, $matches) ? $matches[2] : true;
                continue;
            }
            $positional[] = $argument;
        }

        return [$positional, $options];
    }

    private function printHelp(): void
    {
        $this->line('FloCMS API commands (php flo <command>, or vendor/bin/flo-api <command>):');
        foreach ($this->commands as $command) {
            $this->line();
            $this->line('  ' . $command[0]);
            $this->line('      ' . $command[1]);
        }
    }

    private function registerDefaults(): void
    {
        $this->register('api:install-schema', 'api:install-schema', 'Create the API tables (api_keys, api_tokens, ...).', function (): int {
            $count = (new SchemaInstaller($this->connection()))->install();
            $this->line('API schema installed (' . $count . ' statements, driver ' . $this->connection()->driver() . ').');

            return 0;
        });

        $this->register(
            'api:key:create',
            'api:key:create <name> [--scopes=a,b] [--user=ID] [--expires=YYYY-MM-DD]',
            'Create an API key. The key is shown once.',
            function (array $args, array $options): int {
                $name = $args[0] ?? throw new InvalidArgumentException('A key name is required.');
                $issued = (new ApiKeyRepository($this->connection()))->create(
                    $name,
                    self::listOption($options, 'scopes'),
                    self::intOption($options, 'user'),
                    self::stringOption($options, 'expires')
                );
                $this->line('API key #' . $issued->id . ' created. Store it now; it cannot be shown again:');
                $this->line($issued->plaintext);

                return 0;
            }
        );

        $this->register('api:key:list', 'api:key:list', 'List API keys (never the secrets).', function (): int {
            $this->table((new ApiKeyRepository($this->connection()))->all(), ['id', 'name', 'user_id', 'scopes', 'last_used_at', 'expires_at', 'revoked_at']);

            return 0;
        });

        $this->register('api:key:revoke', 'api:key:revoke <id>', 'Revoke an API key.', function (array $args): int {
            $id = self::idArgument($args);
            $revoked = (new ApiKeyRepository($this->connection()))->revoke($id);
            $this->line($revoked ? 'API key #' . $id . ' revoked.' : 'API key #' . $id . ' not found or already revoked.');

            return $revoked ? 0 : 1;
        });

        $this->register(
            'api:token:create',
            'api:token:create <user-id> <name> [--scopes=a,b] [--expires=YYYY-MM-DD]',
            'Issue a personal access token for a user. The token is shown once.',
            function (array $args, array $options): int {
                $userId = self::idArgument($args);
                $name = $args[1] ?? throw new InvalidArgumentException('A token name is required.');
                $issued = (new TokenRepository($this->connection()))->issue(
                    $userId,
                    $name,
                    self::listOption($options, 'scopes'),
                    self::stringOption($options, 'expires')
                );
                $this->line('Token #' . $issued->id . ' issued for user #' . $userId . '. Store it now; it cannot be shown again:');
                $this->line($issued->plaintext);

                return 0;
            }
        );

        $this->register('api:token:list', 'api:token:list [--user=ID]', 'List personal access tokens (never the secrets).', function (array $args, array $options): int {
            $tokens = new TokenRepository($this->connection());
            $user = self::intOption($options, 'user');
            $this->table($user !== null ? $tokens->forUser($user) : $tokens->all(), ['id', 'user_id', 'name', 'scopes', 'last_used_at', 'expires_at', 'revoked_at']);

            return 0;
        });

        $this->register('api:token:revoke', 'api:token:revoke <id> | --user=ID', 'Revoke a token, or every token of a user.', function (array $args, array $options): int {
            $tokens = new TokenRepository($this->connection());
            $user = self::intOption($options, 'user');
            if ($user !== null) {
                $this->line($tokens->revokeAllForUser($user) . ' token(s) of user #' . $user . ' revoked.');

                return 0;
            }
            $id = self::idArgument($args);
            $revoked = $tokens->revoke($id);
            $this->line($revoked ? 'Token #' . $id . ' revoked.' : 'Token #' . $id . ' not found or already revoked.');

            return $revoked ? 0 : 1;
        });

        $this->register(
            'api:gc',
            'api:gc [--rate-limit-dir=storage/cache/api-rate-limit] [--idempotency-dir=storage/cache/api-idempotency] [--max-age=86400] [--database]',
            'Delete stale rate-limit and idempotency state (--database: also the api_idempotency table). Safe to run from cron.',
            function (array $args, array $options): int {
                $directory = $this->path(self::stringOption($options, 'rate-limit-dir') ?? 'storage/cache/api-rate-limit');
                $maxAge = self::intOption($options, 'max-age') ?? 86400;
                $removed = (new FileRateLimiter($directory, 0))->gc($maxAge);
                $this->line('Rate limits: ' . $removed . ' stale file(s) removed from ' . $directory . '.');

                $idempotency = $this->path(self::stringOption($options, 'idempotency-dir') ?? 'storage/cache/api-idempotency');
                $this->line('Idempotency: ' . (new FileIdempotencyStore($idempotency))->purge() . ' expired file(s) removed from ' . $idempotency . '.');

                if (isset($options['database'])) {
                    $this->line('Idempotency: ' . (new DatabaseIdempotencyStore($this->connection()))->purge() . ' expired row(s) removed from api_idempotency.');
                }

                return 0;
            }
        );

        $this->register(
            'api:routes',
            'api:routes [--routes=api/routes.php] [--prefix=/api]',
            'List API routes with their names and middleware.',
            function (array $args, array $options): int {
                $rows = [];
                foreach ($this->loadRouter($options)->routes() as $route) {
                    $rows[] = [
                        'method' => implode('|', $route->methods()),
                        'path' => $route->pattern(),
                        'name' => $route->name(),
                        'middleware' => array_map([self::class, 'describeMiddleware'], $route->middlewareStack()),
                    ];
                }
                $this->table($rows, ['method', 'path', 'name', 'middleware']);

                return 0;
            }
        );

        $this->register(
            'api:docs',
            'api:docs [--out=public/openapi.json] [--title=...] [--api-version=1.0.0] [--server=https://...] [--routes=api/routes.php] [--prefix=/api]',
            'Generate an OpenAPI 3.1 document from the routes (stdout, or --out=file).',
            function (array $args, array $options): int {
                $json = (new OpenApiGenerator(
                    $this->loadRouter($options),
                    self::stringOption($options, 'title') ?? 'FloCMS API',
                    self::stringOption($options, 'api-version') ?? '1.0.0',
                    self::stringOption($options, 'server')
                ))->toJson();

                $out = self::stringOption($options, 'out');
                if ($out === null) {
                    fwrite($this->stdout, $json);

                    return 0;
                }
                if (file_put_contents($this->path($out), $json) === false) {
                    throw new RuntimeException('Unable to write ' . $out . '.');
                }
                $this->line('OpenAPI document written to ' . $out . '.');

                return 0;
            }
        );
    }

    /**
     * Load the application's routes the way public/api.php does.
     *
     * @param array<string, string|true> $options
     */
    public function loadRouter(array $options): Router
    {
        $file = $this->path(self::stringOption($options, 'routes') ?? 'api/routes.php');
        $prefix = self::stringOption($options, 'prefix') ?? '/api';
        if (!defined('ROOT')) {
            define('ROOT', rtrim($this->projectRoot, '/\\'));
        }
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $router = new Router();
        $router->group($prefix, static function (Router $router) use ($file): void {
            (new RouteLoader($router, new Container()))->load($file);
        });

        return $router;
    }

    private static function describeMiddleware(mixed $middleware): string
    {
        return match (true) {
            is_string($middleware) => $middleware,
            $middleware instanceof Closure => 'closure',
            is_object($middleware) => (new \ReflectionClass($middleware))->getShortName(),
            default => get_debug_type($middleware),
        };
    }

    /** Resolve a project-relative path. */
    public function path(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     */
    public function table(array $rows, array $columns): void
    {
        if ($rows === []) {
            $this->line('(none)');

            return;
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
        foreach ($cells as $line) {
            $padded = [];
            foreach ($line as $index => $cell) {
                $padded[] = $cell . str_repeat(' ', $widths[$index] - mb_strlen($cell));
            }
            $this->line(rtrim(implode('  ', $padded)));
        }
    }

    private function connectFromEnvironment(): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $dsn = getenv('FLO_API_DSN');
        if (is_string($dsn) && $dsn !== '') {
            return new PDO(
                $dsn,
                getenv('FLO_API_DB_USER') ?: null,
                getenv('FLO_API_DB_PASS') ?: null,
                $options
            );
        }

        $envFile = $this->path('.env');
        if (!is_file($envFile)) {
            throw new RuntimeException('No .env file in ' . $this->projectRoot . ' (or set FLO_API_DSN).');
        }
        Env::load($envFile);
        if ((string) Env::get('DB_NAME', '') === '' || (string) Env::get('DB_USERNAME', '') === '') {
            throw new RuntimeException('Set DB_NAME and DB_USERNAME in .env.');
        }

        return new PDO(
            'mysql:host=' . Env::get('DB_HOST', 'localhost')
            . ';port=' . (int) Env::get('DB_PORT', 3306)
            . ';dbname=' . Env::get('DB_NAME')
            . ';charset=' . Env::get('DB_CHARSET', 'utf8mb4'),
            (string) Env::get('DB_USERNAME'),
            (string) Env::get('DB_PASSWORD', ''),
            $options
        );
    }

    /** @param array<string, string|true> $options */
    public static function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if ($value === true) {
            throw new InvalidArgumentException('--' . $name . ' needs a value.');
        }

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /** @param array<string, string|true> $options */
    private static function intOption(array $options, string $name): ?int
    {
        $value = self::stringOption($options, $name);
        if ($value === null) {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('--' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /**
     * @param array<string, string|true> $options
     * @return list<string>
     */
    private static function listOption(array $options, string $name): array
    {
        $value = self::stringOption($options, $name);

        return $value === null ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /** @param list<string> $args */
    private static function idArgument(array $args): int
    {
        $id = $args[0] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new InvalidArgumentException('A positive numeric id is required.');
        }

        return (int) $id;
    }
}
