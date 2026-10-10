<?php
declare(strict_types=1);

namespace FloCMS\Api\OpenApi;

use Attribute;

/**
 * A documented response. $schema describes the `data` of the success
 * envelope (2xx) or the whole body for other statuses; leave it empty to
 * document only the status.
 *
 *     #[ResponseSchema(200, ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]])]
 *     #[ResponseSchema(404, description: 'Property not found')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class ResponseSchema
{
    /** @param array<string, mixed> $schema */
    public function __construct(
        public readonly int $status,
        public readonly array $schema = [],
        public readonly ?string $description = null
    ) {
    }
}
