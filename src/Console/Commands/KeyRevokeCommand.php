<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Auth\ApiKeyRepository;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;

final class KeyRevokeCommand extends ApiCommand
{
    protected string $name = 'api:key:revoke';

    protected string $description = 'Revoke an API key';

    protected function configure(Definition $definition): void
    {
        $definition->argument('id', Argument::REQUIRED, 'The key id (see api:key:list)');
    }

    protected function handle(): int
    {
        $id = $this->idArgument('id');
        $revoked = (new ApiKeyRepository($this->connection()))->revoke($id);
        $this->line($revoked ? 'API key #' . $id . ' revoked.' : 'API key #' . $id . ' not found or already revoked.');

        return $revoked ? self::SUCCESS : self::FAILURE;
    }
}
