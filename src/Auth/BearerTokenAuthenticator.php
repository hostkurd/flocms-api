<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;

/**
 * Authenticates personal access tokens sent as `Authorization: Bearer
 * flt_<id>_<secret>` (or `X-FloCMS-Authorization`, for hosts that strip the
 * Authorization header). The identity is the token's user.
 */
final class BearerTokenAuthenticator implements AuthenticatorInterface
{
    private readonly UserResolver $users;

    /** @param null|callable(int): mixed $userLoader defaults to Config 'auth.user_loader' */
    public function __construct(
        private readonly TokenRepository $tokens,
        ?callable $userLoader = null
    ) {
        $this->users = new UserResolver($userLoader);
    }

    public function authenticate(Request $request, string $guard = 'default'): ?Identity
    {
        $plaintext = $request->bearerToken();
        if ($plaintext === null || !str_starts_with($plaintext, 'flt_')) {
            return null;
        }

        $token = $this->tokens->verify($plaintext);
        if ($token === null) {
            return null;
        }

        $roles = $this->users->roles($token['user_id']);
        if ($roles === null) {
            return null;
        }

        return new Identity(
            $token['user_id'],
            $roles,
            $token['scopes'],
            ['credential' => 'token', 'credential_id' => $token['id'], 'name' => $token['name']]
        );
    }
}
