<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use FloCMS\Api\Database\Connection;
use InvalidArgumentException;

/**
 * Shared storage for API keys and personal access tokens.
 *
 * A credential looks like `<prefix>_<id>_<40 hex chars>`. Only the SHA-256 of
 * the random part is stored; verification looks the row up by id and compares
 * hashes with hash_equals(), so the check runs in constant time.
 *
 * @internal extended by ApiKeyRepository and TokenRepository
 */
abstract class CredentialRepository
{
    /** How often last_used_at is written, at most. */
    private const TOUCH_INTERVAL = 60;

    public function __construct(protected readonly Connection $db)
    {
    }

    abstract protected function table(): string;

    abstract protected function prefix(): string;

    /**
     * The credential's row when it is valid (exists, not revoked, not expired),
     * without the secret hash. Scopes are decoded to a list.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $plaintext): ?array
    {
        $parsed = $this->parse($plaintext);
        $row = $parsed !== null
            ? $this->db->first('SELECT * FROM ' . $this->table() . ' WHERE id = ?', [$parsed[0]])
            : null;

        // Hash even when there is no row, so lookups take the same time.
        $expected = is_array($row) ? (string) $row['secret_hash'] : str_repeat('0', 64);
        $matches = hash_equals($expected, hash('sha256', $parsed[1] ?? ''));
        if ($row === null || !$matches) {
            return null;
        }

        $now = Connection::now();
        if ($row['revoked_at'] !== null || ($row['expires_at'] !== null && (string) $row['expires_at'] <= $now)) {
            return null;
        }

        if ($row['last_used_at'] === null || (string) $row['last_used_at'] < Connection::now(-self::TOUCH_INTERVAL)) {
            $this->db->execute(
                'UPDATE ' . $this->table() . ' SET last_used_at = ? WHERE id = ?',
                [$now, (int) $row['id']]
            );
            $row['last_used_at'] = $now;
        }

        return $this->present($row);
    }

    public function revoke(int $id): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->table() . ' SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [Connection::now(), $id]
        )->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM ' . $this->table() . ' WHERE id = ?', [$id]);

        return $row !== null ? $this->present($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(
            fn (array $row): array => $this->present($row),
            $this->db->all('SELECT * FROM ' . $this->table() . ' ORDER BY id')
        );
    }

    /**
     * @param array<string, mixed> $columns
     * @param list<string> $scopes
     */
    protected function insert(array $columns, array $scopes, ?string $expiresAt): IssuedCredential
    {
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !preg_match('/^[A-Za-z0-9_.:*-]{1,100}$/', $scope)) {
                throw new InvalidArgumentException('Invalid scope: ' . (is_string($scope) ? $scope : gettype($scope)));
            }
        }
        if ($expiresAt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $expiresAt)) {
            throw new InvalidArgumentException('Expiry must be "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".');
        }
        if ($expiresAt !== null && strlen($expiresAt) === 10) {
            $expiresAt .= ' 00:00:00';
        }

        $secret = bin2hex(random_bytes(20));
        $columns += [
            'secret_hash' => hash('sha256', $secret),
            'scopes' => json_encode(array_values(array_unique($scopes)), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'created_at' => Connection::now(),
        ];

        $this->db->execute(
            'INSERT INTO ' . $this->table() . ' (' . implode(', ', array_keys($columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($columns)
        );
        $id = (int) $this->db->pdo()->lastInsertId();

        return new IssuedCredential($id, $this->prefix() . '_' . $id . '_' . $secret);
    }

    /** @return array{0: int, 1: string}|null */
    private function parse(string $plaintext): ?array
    {
        $pattern = '/^' . preg_quote($this->prefix(), '/') . '_([1-9][0-9]{0,18})_([0-9a-f]{40})$/D';
        if (preg_match($pattern, trim($plaintext), $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], $matches[2]];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function present(array $row): array
    {
        unset($row['secret_hash']);
        $row['id'] = (int) $row['id'];
        if (array_key_exists('user_id', $row) && $row['user_id'] !== null) {
            $row['user_id'] = (int) $row['user_id'];
        }
        $scopes = is_string($row['scopes'] ?? null) ? json_decode($row['scopes'], true) : [];
        $row['scopes'] = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];

        return $row;
    }
}
