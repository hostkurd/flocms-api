<?php
declare(strict_types=1);

namespace FloCMS\Api\OpenApi;

use Attribute;

/**
 * The request body of an endpoint, as a JSON Schema:
 *
 *     #[RequestBody(['type' => 'object', 'required' => ['email'], 'properties' => ['email' => ['type' => 'string', 'format' => 'email']]])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class RequestBody
{
    /** @param array<string, mixed> $schema */
    public function __construct(
        public readonly array $schema,
        public readonly bool $required = true,
        public readonly string $contentType = 'application/json',
        public readonly ?string $description = null
    ) {
    }
}
