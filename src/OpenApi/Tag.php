<?php
declare(strict_types=1);

namespace FloCMS\Api\OpenApi;

use Attribute;

/** Groups endpoints in the generated OpenAPI document. On a class, applies to all its handlers. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Tag
{
    public function __construct(public readonly string $name)
    {
    }
}
