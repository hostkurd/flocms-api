<?php
declare(strict_types=1);

namespace FloCMS\Api\RateLimit;

/*
 * Moved to flocms-core 2.8 as \FloCMS\Core\RateLimit\RateLimiterInterface, so websites
 * without the API can use it. This name stays as an alias of the core
 * interface (the same interface, not a copy) until flocms-api 2.0.
 *
 * src/aliases.php (autoload "files") loads it on every request: PHP does not
 * autoload a class named only in a parameter type, so an alias made on first
 * use would leave `function f(OldName $x)` refusing objects made with the
 * core name.
 */
if (!class_exists(RateLimiterInterface::class, false) && !interface_exists(RateLimiterInterface::class, false)) {
    class_alias(\FloCMS\Core\RateLimit\RateLimiterInterface::class, RateLimiterInterface::class);
}

// Never runs: tells editors and static analysis that this name is deprecated
// and has everything the core class has.
if (false) {
    /** @deprecated 1.3 Use \FloCMS\Core\RateLimit\RateLimiterInterface (flocms-core 2.8). */
    interface RateLimiterInterface extends \FloCMS\Core\RateLimit\RateLimiterInterface {}
}
