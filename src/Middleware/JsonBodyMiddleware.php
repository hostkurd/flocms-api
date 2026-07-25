<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly int $maxBytes = 2_097_152)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $contentType = $request->contentType();
        $isJson = $contentType === 'application/json' || str_ends_with($contentType, '+json');
        if (!$isJson) {
            return $handler->handle($request);
        }

        if (strlen($request->rawBody()) > $this->maxBytes) {
            throw new ApiException('JSON request body is too large.', 413);
        }

        $request->json();
        if ($request->jsonError() !== null) {
            throw new ApiException('Malformed JSON request body.', 400);
        }

        return $handler->handle($request);
    }
}
