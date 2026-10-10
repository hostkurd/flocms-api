<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Database\SchemaInstaller;
use FloCMS\CLI\Database\Migrator;
use FloCMS\Core\Database;

/**
 * Since 1.2 the API tables are a migration (database/migrations), run by
 * `php flo migrate` with the application's migrations. This command runs
 * only the API's migrations.
 */
final class InstallSchemaCommand extends ApiCommand
{
    public const PACKAGE = 'hostkurd/flocms-api';

    protected string $name = 'api:install-schema';

    protected string $description = 'Create the API tables (api_keys, api_tokens, ...): the API\'s migrations';

    protected string $help = 'Same as `php flo migrate --package=hostkurd/flocms-api`. Safe to run again.';

    protected function handle(): int
    {
        $connection = $this->connection();
        $migrator = new Migrator($this->project(), new Database($connection->pdo()), (string) $this->project()->env('DB_MIGRATIONS_TABLE', 'migrations'));

        if ($migrator->files(self::PACKAGE) === []) {
            // flocms-api is not installed through Composer (development): no migration list
            $count = (new SchemaInstaller($connection))->install();
            $this->line('API schema installed (' . $count . ' statements, driver ' . $connection->driver() . ').');

            return self::SUCCESS;
        }

        $ran = $migrator->migrate(self::PACKAGE);
        $this->line($ran === []
            ? 'API schema is up to date (driver ' . $connection->driver() . ').'
            : 'API schema installed (' . count($ran) . ' migration(s), driver ' . $connection->driver() . ').');

        return self::SUCCESS;
    }
}
