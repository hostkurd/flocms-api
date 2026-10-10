<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use FloCMS\Api\Database\Connection;
use InvalidArgumentException;

/**
 * Personal access tokens (table api_tokens) for user-facing apps, sent as
 * `Authorization: Bearer flt_<id>_<secret>`. Only the hash of the secret is
 * stored.
 */
final class TokenRepository extends CredentialRepository
{
    /**
     * @param list<string> $scopes
     * @param string|null $expiresAt "YYYY-MM-DD[ HH:MM:SS]", or null for no expiry
     */
    public function issue(int $userId, string $name, array $scopes = [], ?string $expiresAt = null): IssuedCredential
    {
        $name = trim($name);
        if ($userId < 1) {
            throw new InvalidArgumentException('A positive user id is required.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('A token name of 1 to 100 characters is required.');
        }

        return $this->insert(['user_id' => $userId, 'name' => $name], $scopes, $expiresAt);
    }

    /** Issue a token that expires $seconds from now. */
    public function issueFor(int $userId, string $name, int $seconds, array $scopes = []): IssuedCredential
    {
        return $this->issue($userId, $name, $scopes, Connection::now(max(1, $seconds)));
    }

    /** Revoke every active token of a user (e.g. on password change). Returns how many. */
    public function revokeAllForUser(int $userId): int
    {
        return $this->db->execute(
            'UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
            [Connection::now(), $userId]
        )->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return array_map(
            fn (array $row): array => $this->present($row),
            $this->db->all('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY id', [$userId])
        );
    }

    protected function table(): string
    {
        return 'api_tokens';
    }

    protected function prefix(): string
    {
        return 'flt';
    }
}
