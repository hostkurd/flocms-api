<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Auth\ApiKeyRepository;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class KeyCreateCommand extends ApiCommand
{
    protected string $name = 'api:key:create';

    protected string $description = 'Create an API key. The key is shown once.';

    protected array $examples = ['php flo api:key:create "Mobile app" --scopes=properties.read --expires=2027-12-31'];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'A name for the key')
            ->option('scopes', null, Option::VALUE_REQUIRED, 'Comma separated scopes')
            ->option('user', null, Option::VALUE_REQUIRED, 'The user the key acts as')
            ->option('expires', null, Option::VALUE_REQUIRED, 'Expiry date (YYYY-MM-DD)');
    }

    protected function handle(): int
    {
        $issued = (new ApiKeyRepository($this->connection()))->create(
            (string) $this->argument('name'),
            $this->listOption('scopes'),
            $this->intOption('user'),
            $this->stringOption('expires')
        );
        $this->line('API key #' . $issued->id . ' created. Store it now; it cannot be shown again:');
        $this->line($issued->plaintext);

        return self::SUCCESS;
    }
}
