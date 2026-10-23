<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use Closure;
use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Core\RateLimit\RateLimiterInterface;
use FloCMS\Core\Security\ClientIpResolver;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    private readonly ?Closure $keyResolver;

    /**
     * @param null|callable(Request): string $keyResolver
     * @param bool $keyByIdentity key authenticated requests by their identity
     *        (set by AuthenticateMiddleware) instead of the client IP
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly ClientIpResolver $clientIp,
        private readonly string $bucket,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        ?callable $keyResolver = null,
        private readonly bool $keyByIdentity = false
    ) {
        $this->keyResolver = $keyResolver !== null ? Closure::fromCallable($keyResolver) : null;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // OPTIONS (CORS preflights, Allow discovery) never runs a handler.
        if ($request->method() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $result = $this->limiter->hit(
            $this->bucket . '|' . $this->subject($request),
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

    private function subject(Request $request): string
    {
        if ($this->keyResolver !== null) {
            return (string) ($this->keyResolver)($request);
        }

        if ($this->keyByIdentity) {
            $identity = $request->attribute('identity');

            return $identity instanceof Identity
                ? 'id:' . $identity->id
                : 'ip:' . $this->clientIp->resolve($request);
        }

        return $this->clientIp->resolve($request);
    }
}
