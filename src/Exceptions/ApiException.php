<?php
declare(strict_types=1);

namespace FloCMS\Api\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $errors
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly array $errors = [],
        public readonly array $headers = []
    ) {
        parent::__construct($message, $status);
    }
}
