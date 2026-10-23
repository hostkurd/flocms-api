<?php
declare(strict_types=1);

/*
 * The old names of the classes that moved to flocms-core 2.8 (deprecated,
 * removed in flocms-api 2.0), made at autoload time so they always exist:
 * a parameter type such as `RateLimiterInterface $limiter` then accepts the
 * core objects even when nothing loaded the old name before.
 */
foreach ([
    'Security/ClientIpResolver', 'Security/IpMatcher',
    'RateLimit/RateLimiterInterface', 'RateLimit/RateLimitResult',
    'RateLimit/FileRateLimiter', 'RateLimit/ApcuRateLimiter',
] as $flocmsApiMovedClass) {
    require_once __DIR__ . '/' . $flocmsApiMovedClass . '.php';
}
unset($flocmsApiMovedClass);
