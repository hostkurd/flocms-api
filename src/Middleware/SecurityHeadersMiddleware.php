<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        return $handler->handle($request)
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->header('Cache-Control', 'no-store');
    }
}
