<?php
declare(strict_types=1);

namespace FloCMS\Api\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Carries response headers (e.g. CORS) alongside an unexpected exception, so
 * the outer error handler can log and render the original and still add them.
 *
 * @internal
 */
final class DecoratedException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly Throwable $error,
        public readonly array $headers
    ) {
        parent::__construct($error->getMessage(), 0, $error);
    }
}
