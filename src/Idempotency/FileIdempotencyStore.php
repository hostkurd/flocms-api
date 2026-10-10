<?php
declare(strict_types=1);

namespace FloCMS\Api\Idempotency;

use RuntimeException;

/**
 * Idempotency records as files (one per key), locked with flock(). Needs no
 * database; e.g. storage/cache/api-idempotency.
 */
final class FileIdempotencyStore implements IdempotencyStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function reserve(string $key, string $fingerprint, int $ttlSeconds): ?array
    {
        return $this->locked($key, function ($handle) use ($fingerprint, $ttlSeconds): ?array {
            $record = $this->read($handle);
            if ($record !== null && $record['expires_at'] > time()) {
                return [
                    'status' => $record['status'],
                    'fingerprint' => $record['fingerprint'],
                    'response' => $record['response'] ?? null,
                ];
            }

            $this->write($handle, [
                'status' => 'pending',
                'fingerprint' => $fingerprint,
                'response' => null,
                'expires_at' => time() + $ttlSeconds,
            ]);

            return null;
        });
    }

    public function complete(string $key, array $response, int $ttlSeconds): void
    {
        $this->locked($key, function ($handle) use ($response, $ttlSeconds) {
            $record = $this->read($handle) ?? ['fingerprint' => ''];
            $this->write($handle, [
                'status' => 'done',
                'fingerprint' => $record['fingerprint'],
                'response' => $response,
                'expires_at' => time() + $ttlSeconds,
            ]);

            return null;
        });
    }

    public function release(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function purge(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;
        foreach (glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (!preg_match('/^[0-9a-f]{64}\.json$/', basename($file))) {
                continue;
            }
            $record = json_decode((string) @file_get_contents($file), true);
            $expired = !is_array($record) || (int) ($record['expires_at'] ?? 0) <= time();
            if ($expired && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @template T
     * @param callable(resource): T $callback
     * @return T
     */
    private function locked(string $key, callable $callback): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the idempotency directory.');
        }
        $handle = fopen($this->path($key), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open idempotency state.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock idempotency state.');
            }

            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return array<string, mixed>|null
     */
    private function read($handle): ?array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        $record = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;

        return is_array($record) && isset($record['status'], $record['fingerprint'], $record['expires_at'])
            ? $record
            : null;
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $record
     */
    private function write($handle, array $record): void
    {
        $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false || !fflush($handle)) {
            throw new RuntimeException('Unable to persist idempotency state.');
        }
    }

    private function path(string $key): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
            throw new RuntimeException('Idempotency keys must be SHA-256 hashes.');
        }

        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
