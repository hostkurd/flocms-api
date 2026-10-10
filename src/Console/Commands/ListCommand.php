<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

/**
 * `php flo api:list`: the API commands (same as `php flo list api`).
 */
final class ListCommand extends ApiCommand
{
    protected string $name = 'api:list';

    protected string $description = 'List the API commands';

    protected function handle(): int
    {
        return $this->call('list', ['api']);
    }
}
