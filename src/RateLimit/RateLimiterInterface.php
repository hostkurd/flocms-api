<?php
declare(strict_types=1);

namespace FloCMS\Api\RateLimit;

/*
 * Moved to flocms-core 2.8 as \FloCMS\Core\RateLimit\RateLimiterInterface, so websites
 * without the API can use it. This name stays as an alias of the core
 * interface (the same interface, not a copy) until flocms-api 2.0.
 */
class_alias(\FloCMS\Core\RateLimit\RateLimiterInterface::class, RateLimiterInterface::class);

// Never runs: tells editors and static analysis that this name is deprecated.
if (false) {
    /** @deprecated 1.3 Use \FloCMS\Core\RateLimit\RateLimiterInterface (flocms-core 2.8). */
    interface RateLimiterInterface {}
}
