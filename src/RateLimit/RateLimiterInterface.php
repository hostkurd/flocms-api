<?php
declare(strict_types=1);

namespace FloCMS\Api\RateLimit;

interface RateLimiterInterface
{
    public function hit(string $key, int $maxAttempts, int $windowSeconds): RateLimitResult;

    public function clear(string $key): void;
}
