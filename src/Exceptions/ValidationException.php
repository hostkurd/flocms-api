<?php
declare(strict_types=1);

namespace FloCMS\Api\Exceptions;

use FloCMS\Core\ValidationException as CoreValidationException;

final class ValidationException extends ApiException
{
    /** @param array<string, mixed> $errors field => list of messages */
    public function __construct(array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message, 422, $errors);
    }

    /** Convert the exception thrown by core Validator::validate(). */
    public static function fromCore(CoreValidationException $error): self
    {
        return new self($error->getErrors());
    }
}
