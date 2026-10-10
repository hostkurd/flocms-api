<?php
declare(strict_types=1);

namespace FloCMS\Api\Idempotency;

use FloCMS\Api\Database\Connection;
use PDOException;

/**
 * Idempotency records in the api_idempotency table (see database/schema).
 * The primary key makes reserve() atomic across PHP processes.
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function reserve(string $key, string $fingerprint, int $ttlSeconds): ?array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->db->execute(
                    'INSERT INTO api_idempotency (idempotency_key, fingerprint, status, response, expires_at, created_at)'
                    . ' VALUES (?, ?, ?, NULL, ?, ?)',
                    [$key, $fingerprint, 'pending', Connection::now($ttlSeconds), Connection::now()]
                );

                return null;
            } catch (PDOException $error) {
                if (!self::isDuplicateKey($error)) {
                    throw $error;
                }
            }

            $row = $this->db->first('SELECT * FROM api_idempotency WHERE idempotency_key = ?', [$key]);
            if ($row === null) {
                continue; // released in between: try to claim again
            }
            if ((string) $row['expires_at'] > Connection::now()) {
                $response = is_string($row['response']) ? json_decode($row['response'], true) : null;

                return [
                    'status' => (string) $row['status'],
                    'fingerprint' => (string) $row['fingerprint'],
                    'response' => is_array($response) ? $response : null,
                ];
            }

            $this->db->execute(
                'DELETE FROM api_idempotency WHERE idempotency_key = ? AND expires_at <= ?',
                [$key, Connection::now()]
            );
        }

        return ['status' => 'pending', 'fingerprint' => $fingerprint, 'response' => null];
    }

    public function complete(string $key, array $response, int $ttlSeconds): void
    {
        $this->db->execute(
            'UPDATE api_idempotency SET status = ?, response = ?, expires_at = ? WHERE idempotency_key = ?',
            [
                'done',
                json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                Connection::now($ttlSeconds),
                $key,
            ]
        );
    }

    public function release(string $key): void
    {
        $this->db->execute('DELETE FROM api_idempotency WHERE idempotency_key = ?', [$key]);
    }

    public function purge(): int
    {
        return $this->db->execute('DELETE FROM api_idempotency WHERE expires_at <= ?', [Connection::now()])->rowCount();
    }

    private static function isDuplicateKey(PDOException $error): bool
    {
        // SQLSTATE 23000 (MySQL/MariaDB, SQLite): integrity constraint violation.
        return (string) $error->getCode() === '23000'
            || str_contains($error->getMessage(), 'UNIQUE constraint failed');
    }
}
