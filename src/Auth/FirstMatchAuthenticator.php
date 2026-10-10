<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;

/**
 * Tries several authenticators in order and returns the first identity, e.g.
 * bearer tokens for apps and the admin session for the admin panel.
 */
final class FirstMatchAuthenticator implements AuthenticatorInterface
{
    /** @var list<AuthenticatorInterface> */
    private readonly array $authenticators;

    public function __construct(AuthenticatorInterface ...$authenticators)
    {
        $this->authenticators = array_values($authenticators);
    }

    public function authenticate(Request $request, string $guard = 'default'): ?Identity
    {
        foreach ($this->authenticators as $authenticator) {
            $identity = $authenticator->authenticate($request, $guard);
            if ($identity !== null) {
                return $identity;
            }
        }

        return null;
    }
}
