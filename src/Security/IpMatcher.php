<?php
declare(strict_types=1);

namespace FloCMS\Api\Security;

/*
 * Moved to flocms-core 2.8 as \FloCMS\Core\Security\IpMatcher, so websites
 * without the API can use it. This name stays as an alias of the core
 * class (the same class, not a copy) until flocms-api 2.0.
 */
class_alias(\FloCMS\Core\Security\IpMatcher::class, IpMatcher::class);

// Never runs: tells editors and static analysis that this name is deprecated.
if (false) {
    /** @deprecated 1.3 Use \FloCMS\Core\Security\IpMatcher (flocms-core 2.8). */
    class IpMatcher {}
}
