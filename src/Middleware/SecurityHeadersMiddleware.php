<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @internal */
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cache-Control' => 'no-store',
    ];

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);
        foreach (self::HEADERS as $name => $value) {
            // A route may opt into caching (see CacheControl); keep its policy.
            if ($name === 'Cache-Control' && self::hasHeader($response, $name)) {
                continue;
            }
            $response->header($name, $value);
        }

        return $response;
    }

    private static function hasHeader(Response $response, string $name): bool
    {
        foreach (array_keys($response->headers()) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
