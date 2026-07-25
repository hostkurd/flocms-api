<?php
declare(strict_types=1);

namespace FloCMS\Api\Exceptions;

final class ValidationException extends ApiException
{
    /** @param array<string, mixed> $errors */
    public function __construct(array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message, 422, $errors);
    }
}
