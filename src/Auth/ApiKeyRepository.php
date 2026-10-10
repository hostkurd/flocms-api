<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use InvalidArgumentException;

/**
 * API keys (table api_keys) for server-to-server clients. Keys look like
 * `flk_<id>_<secret>`; only the hash of the secret is stored.
 */
final class ApiKeyRepository extends CredentialRepository
{
    /**
     * @param list<string> $scopes
     * @param int|null $userId act as this user (roles are loaded from it); null for a service key
     * @param string|null $expiresAt "YYYY-MM-DD[ HH:MM:SS]", or null for no expiry
     */
    public function create(string $name, array $scopes = [], ?int $userId = null, ?string $expiresAt = null): IssuedCredential
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('A key name of 1 to 100 characters is required.');
        }

        return $this->insert(['name' => $name, 'user_id' => $userId], $scopes, $expiresAt);
    }

    protected function table(): string
    {
        return 'api_keys';
    }

    protected function prefix(): string
    {
        return 'flk';
    }
}
