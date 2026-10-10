<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Database\SchemaInstaller;

final class InstallSchemaCommand extends ApiCommand
{
    protected string $name = 'api:install-schema';

    protected string $description = 'Create the API tables (api_keys, api_tokens, ...)';

    protected function handle(): int
    {
        $count = (new SchemaInstaller($this->connection()))->install();
        $this->line('API schema installed (' . $count . ' statements, driver ' . $this->connection()->driver() . ').');

        return self::SUCCESS;
    }
}
