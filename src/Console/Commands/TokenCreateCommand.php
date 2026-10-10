<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Auth\TokenRepository;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class TokenCreateCommand extends ApiCommand
{
    protected string $name = 'api:token:create';

    protected string $description = 'Issue a personal access token for a user. The token is shown once.';

    protected array $examples = ['php flo api:token:create 12 "Phone" --scopes=properties.read'];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('user-id', Argument::REQUIRED, 'The user id')
            ->argument('name', Argument::REQUIRED, 'A name for the token')
            ->option('scopes', null, Option::VALUE_REQUIRED, 'Comma separated scopes')
            ->option('expires', null, Option::VALUE_REQUIRED, 'Expiry date (YYYY-MM-DD)');
    }

    protected function handle(): int
    {
        $userId = $this->idArgument('user-id');
        $issued = (new TokenRepository($this->connection()))->issue(
            $userId,
            (string) $this->argument('name'),
            $this->listOption('scopes'),
            $this->stringOption('expires')
        );
        $this->line('Token #' . $issued->id . ' issued for user #' . $userId . '. Store it now; it cannot be shown again:');
        $this->line($issued->plaintext);

        return self::SUCCESS;
    }
}
