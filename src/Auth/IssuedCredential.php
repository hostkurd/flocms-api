<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

/**
 * A newly created API key or token. $plaintext is the only copy of the
 * secret: show it to the user once, it cannot be recovered later.
 */
final class IssuedCredential
{
    public function __construct(
        public readonly int $id,
        public readonly string $plaintext
    ) {
    }
}
