<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Auth\ApiKeyRepository;

final class KeyListCommand extends ApiCommand
{
    protected string $name = 'api:key:list';

    protected string $description = 'List API keys (never the secrets)';

    protected function handle(): int
    {
        $this->plainTable((new ApiKeyRepository($this->connection()))->all(), ['id', 'name', 'user_id', 'scopes', 'last_used_at', 'expires_at', 'revoked_at']);

        return self::SUCCESS;
    }
}
