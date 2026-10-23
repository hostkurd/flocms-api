<?php
declare(strict_types=1);

namespace FloCMS\Api\RateLimit;

use FloCMS\Api\Middleware\RateLimitMiddleware;
use FloCMS\Core\RateLimit\RateLimiterInterface;
use FloCMS\Core\Security\ClientIpResolver;
use InvalidArgumentException;

/**
 * Named rate limits, configured once and attached per route:
 *
 *     $limiters = new RateLimiterRegistry($limiter, $clientIp, [
 *         'public' => [60, 60],     // 60 requests per minute
 *         'forms'  => [5, 60],
 *         'auth'   => [10, 900],    // 10 per 15 minutes
 *     ]);
 *     $kernel->aliases(['throttle' => $limiters->aliasFactory()]);
 *     $router->post('/v1/inquiries', $handler)->middleware('throttle:forms');
 *
 * Requests are keyed by identity when authenticated (put the throttle after
 * AuthenticateMiddleware), by client IP otherwise.
 */
final class RateLimiterRegistry
{
    /** @var array<string, array{0: int, 1: int}> */
    private array $limits = [];

    /** @param array<string, array{0: int, 1: int}> $limits name => [max attempts, window seconds] */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly ClientIpResolver $clientIp,
        array $limits = []
    ) {
        foreach ($limits as $name => $limit) {
            if (!is_array($limit) || count($limit) !== 2) {
                throw new InvalidArgumentException('Rate limit "' . $name . '" must be [max, windowSeconds].');
            }
            $this->define($name, (int) array_values($limit)[0], (int) array_values($limit)[1]);
        }
    }

    public function define(string $name, int $maxAttempts, int $windowSeconds): self
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new InvalidArgumentException('Invalid rate limit name: ' . $name);
        }
        if ($maxAttempts < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('Rate limit "' . $name . '" values must be positive.');
        }
        $this->limits[$name] = [$maxAttempts, $windowSeconds];

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->limits[$name]);
    }

    public function middleware(string $name): RateLimitMiddleware
    {
        $limit = $this->limits[$name] ?? throw new InvalidArgumentException('Unknown rate limit: ' . $name);

        return new RateLimitMiddleware(
            $this->limiter,
            $this->clientIp,
            'throttle:' . $name,
            $limit[0],
            $limit[1],
            keyByIdentity: true
        );
    }

    /** @return callable(string): RateLimitMiddleware for Kernel::aliases(['throttle' => ...]) */
    public function aliasFactory(): callable
    {
        return fn (string $name = 'public'): RateLimitMiddleware => $this->middleware($name);
    }
}
