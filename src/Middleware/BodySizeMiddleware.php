<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;

/**
 * Rejects request bodies larger than $maxBytes with 413, for every content type.
 *
 * The declared Content-Length is checked first, so oversized multipart uploads
 * are rejected without inspecting them. PHP's own post_max_size and
 * upload_max_filesize still apply and should be set at least as high.
 */
final class BodySizeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly int $maxBytes = 2_097_152)
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The body-size limit must be positive.');
        }
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $declared = trim((string) $request->header('Content-Length', ''));
        if ($declared !== '') {
            if (!ctype_digit($declared)) {
                throw new ApiException('Invalid Content-Length header.', 400);
            }
            // Compared as strings first so huge values cannot overflow.
            if (strlen(ltrim($declared, '0')) > 18 || (int) $declared > $this->maxBytes) {
                throw $this->tooLarge();
            }
        }

        if (strlen($request->rawBody()) > $this->maxBytes) {
            throw $this->tooLarge();
        }

        return $handler->handle($request);
    }

    private function tooLarge(): ApiException
    {
        return new ApiException(
            'Request body is too large.',
            413,
            ['max_bytes' => $this->maxBytes]
        );
    }
}
