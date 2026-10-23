<?php
declare(strict_types=1);

namespace FloCMS\Api\RateLimit;

/*
 * Moved to flocms-core 2.8 as \FloCMS\Core\RateLimit\ApcuRateLimiter, so websites
 * without the API can use it. This name stays as an alias of the core
 * class (the same class, not a copy) until flocms-api 2.0.
 *
 * src/aliases.php (autoload "files") loads it on every request: PHP does not
 * autoload a class named only in a parameter type, so an alias made on first
 * use would leave `function f(OldName $x)` refusing objects made with the
 * core name.
 */
if (!class_exists(ApcuRateLimiter::class, false) && !interface_exists(ApcuRateLimiter::class, false)) {
    class_alias(\FloCMS\Core\RateLimit\ApcuRateLimiter::class, ApcuRateLimiter::class);
}

// Never runs: tells editors and static analysis that this name is deprecated.
if (false) {
    /** @deprecated 1.3 Use \FloCMS\Core\RateLimit\ApcuRateLimiter (flocms-core 2.8). */
    class ApcuRateLimiter {}
}
