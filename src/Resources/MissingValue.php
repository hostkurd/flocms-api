<?php
declare(strict_types=1);

namespace FloCMS\Api\Resources;

/**
 * Marks a resource field to leave out (see JsonResource::when()).
 *
 * @internal
 */
final class MissingValue
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
