<?php
declare(strict_types=1);

namespace FloCMS\Api\Console;

use Closure;
use FloCMS\Api\Console\Commands;
use FloCMS\Api\Console\Commands\ApiCommand;
use FloCMS\Api\Database\Connection;
use FloCMS\CLI\Console\Application as CliApplication;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\CommandNotFoundException;
use FloCMS\CLI\Console\ErrorRenderer;
use FloCMS\CLI\Console\Output\Output;
use FloCMS\CLI\Project;
use InvalidArgumentException;
use Throwable;

/**
 * The `api:*` commands outside the FloCMS CLI, as in flocms-api 1.1:
 * `vendor/bin/flo-api api:key:list`, or embedded in code and tests:
 *
 *     (new Application($root, $connection, $stdout, $stderr))->run(['api:key:list']);
 *
 * Since 1.2 the commands are flocms-cli commands (FloCMS\Api\Console\Commands)
 * registered through composer.json, so `php flo api:...` runs them directly.
 * This class keeps the 1.1 behavior: invalid usage exits with 1.
 *
 * @deprecated 1.2 Run `php flo api:...` (flocms-cli 2), or use
 *             FloCMS\CLI\Console\Testing\ApplicationTester in tests.
 */
final class Application
{
    /** The api:* commands, in listing order. */
    public const COMMANDS = [
        Commands\ListCommand::class,
        Commands\InstallSchemaCommand::class,
        Commands\KeyCreateCommand::class,
        Commands\KeyListCommand::class,
        Commands\KeyRevokeCommand::class,
        Commands\TokenCreateCommand::class,
        Commands\TokenListCommand::class,
        Commands\TokenRevokeCommand::class,
        Commands\GcCommand::class,
        Commands\RoutesCommand::class,
        Commands\DocsCommand::class,
        Commands\InstallCommand::class,
    ];

    private CliApplication $cli;

    private Output $output;

    /** @var array<string, array{0: string, 1: string, 2: Closure(list<string>, array<string, string|true>): int}> commands added with register() */
    private array $closures = [];

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
        $this->output = new Output($stdout, $stderr, $stdout === null ? null : false);
        $this->cli = new CliApplication('FloCMS API commands', '', new Project($projectRoot), $this->output);
        foreach (self::COMMANDS as $class) {
            $this->cli->add((new $class())->useConnection($connection));
        }
    }

    /**
     * Add a command (1.1 API). The handler gets the positional arguments and
     * the options; it returns the exit code.
     *
     * @param string $usage e.g. 'api:key:create <name> [--scopes=a,b]'
     * @param Closure(list<string>, array<string, string|true>): int $handler
     */
    public function register(string $name, string $usage, string $description, Closure $handler): self
    {
        $this->closures[$name] = [$usage, $description, $handler];

        return $this;
    }

    /** @param list<string> $arguments the command line without the script name */
    public function run(array $arguments): int
    {
        $name = $arguments[0] ?? 'list';
        if (in_array($name, ['list', 'api:list', 'help', '--help', '-h'], true)) {
            $this->printHelp();

            return 0;
        }

        if (isset($this->closures[$name])) {
            [$usage, , $handler] = $this->closures[$name];
            [$positional, $options] = self::parse(array_slice($arguments, 1));
            try {
                return $handler($positional, $options);
            } catch (InvalidArgumentException $error) {
                $this->error($error->getMessage());
                $this->error('Usage: ' . $usage);

                return 1;
            } catch (Throwable $error) {
                $this->error(get_class($error) . ': ' . $error->getMessage());

                return 1;
            }
        }

        try {
            $command = $this->cli->find($name);
        } catch (CommandNotFoundException $error) {
            ErrorRenderer::render($error, $this->output, true);
            $this->error('Run "flo api:list" (or "list") for the API commands.');

            return 1;
        }
        $code = $this->cli->runCommand($command, array_values(array_slice($arguments, 1)), $this->output);

        // 1.1 exited with 1 on invalid usage
        return $code === Command::INVALID ? 1 : $code;
    }

    public function line(string $text = ''): void
    {
        $this->output->writeln(\FloCMS\CLI\Console\Output\Formatter::escape($text));
    }

    public function error(string $text): void
    {
        $this->output->error(\FloCMS\CLI\Console\Output\Formatter::escape($text));
    }

    public function connection(): Connection
    {
        return $this->connection ??= ApiCommand::connectionFor(new Project($this->projectRoot));
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * The 1.1 parser: "--name=value" and "--flag" options, the rest positional.
     *
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

    /**
     * Load the application's routes the way public/api.php does.
     *
     * @param array<string, string|true> $options 'routes' and 'prefix'
     */
    public function loadRouter(array $options): \FloCMS\Api\Router
    {
        return ApiCommand::loadRouter(
            $this->projectRoot,
            $this->path(self::stringOption($options, 'routes') ?? 'api/routes.php'),
            self::stringOption($options, 'prefix') ?? '/api'
        );
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
        foreach (ApiCommand::plainTableLines($rows, $columns) as $line) {
            $this->line($line);
        }
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

    private function printHelp(): void
    {
        $this->line('FloCMS API commands (php flo <command>, or vendor/bin/flo-api <command>):');
        foreach ($this->cli->all() as $name => $command) {
            if ($name === 'api:list') {
                continue;
            }
            $this->line();
            $this->line('  ' . $this->cli->usage($command));
            $this->line('      ' . $command->getDescription());
        }
        foreach ($this->closures as [$usage, $description]) {
            $this->line();
            $this->line('  ' . $usage);
            $this->line('      ' . $description);
        }
    }
}
