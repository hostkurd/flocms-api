<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Auth\TokenRepository;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class TokenListCommand extends ApiCommand
{
    protected string $name = 'api:token:list';

    protected string $description = 'List personal access tokens (never the secrets)';

    protected function configure(Definition $definition): void
    {
        $definition->option('user', null, Option::VALUE_REQUIRED, 'Only the tokens of this user');
    }

    protected function handle(): int
    {
        $tokens = new TokenRepository($this->connection());
        $user = $this->intOption('user');
        $this->plainTable($user !== null ? $tokens->forUser($user) : $tokens->all(), ['id', 'user_id', 'name', 'scopes', 'last_used_at', 'expires_at', 'revoked_at']);

        return self::SUCCESS;
    }
}
