<?php
declare(strict_types=1);

namespace FloCMS\Api\Idempotency;

/**
 * Storage for IdempotencyMiddleware. Keys are already hashed and scoped.
 */
interface IdempotencyStore
{
    /**
     * Claim $key atomically. Returns null when the caller now owns the key and
     * should run the request; otherwise the existing record:
     * ['status' => 'pending'|'done', 'fingerprint' => string, 'response' => ?array].
     *
     * @return array{status: string, fingerprint: string, response: ?array<string, mixed>}|null
     */
    public function reserve(string $key, string $fingerprint, int $ttlSeconds): ?array;

    /**
     * Store the response of a claimed key.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    public function complete(string $key, array $response, int $ttlSeconds): void;

    /** Give up a claimed key (the request failed), so it can be retried. */
    public function release(string $key): void;

    /** Remove expired records. Returns how many were removed. */
    public function purge(): int;
}
