<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Auth\TokenRepository;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class TokenRevokeCommand extends ApiCommand
{
    protected string $name = 'api:token:revoke';

    protected string $description = 'Revoke a token, or every token of a user (--user)';

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('id', Argument::OPTIONAL, 'The token id (see api:token:list)')
            ->option('user', null, Option::VALUE_REQUIRED, 'Revoke every token of this user');
    }

    protected function handle(): int
    {
        $tokens = new TokenRepository($this->connection());
        $user = $this->intOption('user');
        if ($user !== null) {
            $this->line($tokens->revokeAllForUser($user) . ' token(s) of user #' . $user . ' revoked.');

            return self::SUCCESS;
        }
        $id = $this->idArgument('id');
        $revoked = $tokens->revoke($id);
        $this->line($revoked ? 'Token #' . $id . ' revoked.' : 'Token #' . $id . ' not found or already revoked.');

        return $revoked ? self::SUCCESS : self::FAILURE;
    }
}
