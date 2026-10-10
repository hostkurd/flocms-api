<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

/**
 * Answers 415 Unsupported Media Type for POST, PUT and PATCH requests with a
 * body that is not JSON. Requests without a body pass.
 *
 * Use `new RequireJsonMiddleware(allowMultipart: true)` on upload routes.
 */
final class RequireJsonMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $allowMultipart = false)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$request->isMethod('POST', 'PUT', 'PATCH') || !$this->hasBody($request)) {
            return $handler->handle($request);
        }

        $type = $request->contentType();
        $accepted = $type === 'application/json'
            || str_ends_with($type, '+json')
            || ($this->allowMultipart && $type === 'multipart/form-data');

        if (!$accepted) {
            throw new ApiException(
                $this->allowMultipart
                    ? 'Request body must be JSON or multipart/form-data.'
                    : 'Request body must be JSON.',
                415
            );
        }

        return $handler->handle($request);
    }

    private function hasBody(Request $request): bool
    {
        $length = trim((string) $request->header('Content-Length', ''));

        return ($length !== '' && $length !== '0')
            || $request->rawBody() !== ''
            || $request->files() !== [];
    }
}
