<?php
declare(strict_types=1);

namespace FloCMS\Api\OpenApi;

use Attribute;

/** Summary and description of an endpoint in the generated OpenAPI document. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS)]
final class Summary
{
    public function __construct(
        public readonly string $summary,
        public readonly ?string $description = null
    ) {
    }
}
