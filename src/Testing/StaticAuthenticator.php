<?php
declare(strict_types=1);

namespace FloCMS\Api\Testing;

use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;

/**
 * An authenticator for tests that always returns the given identity (or
 * none), so protected routes can be tested without real credentials:
 *
 *     new AuthenticateMiddleware(new StaticAuthenticator(new Identity(1, ['2'])))
 */
final class StaticAuthenticator implements AuthenticatorInterface
{
    public function __construct(private readonly ?Identity $identity)
    {
    }

    public function authenticate(Request $request, string $guard = 'default'): ?Identity
    {
        return $this->identity;
    }
}
