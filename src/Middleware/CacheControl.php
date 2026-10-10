<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;

/**
 * Route middleware that sets Cache-Control on successful (2xx or 304) GET/HEAD
 * responses:
 *
 *     $router->get('/v1/pages', $handler)->middleware(CacheControl::public(300));
 *
 * Responses to authenticated requests (an identity, Authorization or
 * X-API-Key header) are never cached publicly: public() becomes private.
 * Error responses and other methods are left alone, so they keep no-store
 * from SecurityHeadersMiddleware. A Cache-Control set by the handler wins.
 */
final class CacheControl implements MiddlewareInterface
{
    private function __construct(
        private readonly string $value,
        private readonly bool $public = false,
        private readonly int $maxAge = 0
    ) {
    }

    /** Shared caches (CDNs, proxies) and browsers may store it for $maxAge seconds. */
    public static function public(int $maxAge, ?int $sharedMaxAge = null): self
    {
        self::assertSeconds($maxAge);
        $value = 'public, max-age=' . $maxAge;
        if ($sharedMaxAge !== null) {
            self::assertSeconds($sharedMaxAge);
            $value .= ', s-maxage=' . $sharedMaxAge;
        }

        return new self($value, true, $maxAge);
    }

    /** Only the client's own browser may store it. */
    public static function private(int $maxAge = 0): self
    {
        self::assertSeconds($maxAge);

        return new self('private, max-age=' . $maxAge);
    }

    /** Caches must revalidate (e.g. with ETag) before reuse. */
    public static function noCache(): self
    {
        return new self('no-cache');
    }

    public static function noStore(): self
    {
        return new self('no-store');
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);
        $status = $response->status();
        // 304 Not Modified must repeat the policy of the 200 it stands for.
        $cacheable = ($status >= 200 && $status < 300) || $status === 304;
        if (!$request->isMethod('GET', 'HEAD') || !$cacheable) {
            return $response;
        }
        foreach (array_keys($response->headers()) as $name) {
            if (strcasecmp($name, 'Cache-Control') === 0) {
                return $response;
            }
        }

        $value = $this->public && self::isAuthenticated($request)
            ? 'private, max-age=' . $this->maxAge
            : $this->value;

        return $response->header('Cache-Control', $value);
    }

    private static function isAuthenticated(Request $request): bool
    {
        return $request->attribute('identity') !== null
            || $request->header('Authorization') !== null
            || $request->header('X-FloCMS-Authorization') !== null
            || $request->header('X-API-Key') !== null;
    }

    private static function assertSeconds(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Cache lifetimes cannot be negative.');
        }
    }
}
