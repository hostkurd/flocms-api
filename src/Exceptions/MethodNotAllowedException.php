<?php
declare(strict_types=1);

namespace FloCMS\Api\Exceptions;

final class MethodNotAllowedException extends ApiException
{
    /** @param list<string> $allowedMethods */
    public function __construct(public readonly array $allowedMethods)
    {
        parent::__construct(
            'Method not allowed.',
            405,
            [],
            ['Allow' => implode(', ', $allowedMethods)]
        );
    }
}
