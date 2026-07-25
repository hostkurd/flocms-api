<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use Closure;
use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\RateLimit\RateLimiterInterface;
use FloCMS\Api\Security\ClientIpResolver;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    private readonly ?Closure $keyResolver;

    /** @param null|callable(Request): string $keyResolver */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly ClientIpResolver $clientIp,
        private readonly string $bucket,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        ?callable $keyResolver = null
    ) {
        $this->keyResolver = $keyResolver !== null ? Closure::fromCallable($keyResolver) : null;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $subject = $this->keyResolver !== null
            ? (string) ($this->keyResolver)($request)
            : $this->clientIp->resolve($request);
        $result = $this->limiter->hit(
            $this->bucket . '|' . $subject,
            $this->maxAttempts,
            $this->windowSeconds
        );

        if (!$result->allowed) {
            throw new ApiException(
                'Too many requests. Please try again later.',
                429,
                [],
                [
                    'Retry-After' => (string) $result->retryAfter,
                    'X-RateLimit-Limit' => (string) $result->limit,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) $result->resetAt,
                ]
            );
        }

        return $handler->handle($request)
            ->header('X-RateLimit-Limit', (string) $result->limit)
            ->header('X-RateLimit-Remaining', (string) $result->remaining)
            ->header('X-RateLimit-Reset', (string) $result->resetAt);
    }
}
