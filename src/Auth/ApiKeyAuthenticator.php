<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;

/**
 * Authenticates `X-API-Key: flk_<id>_<secret>` against the api_keys table.
 *
 * A key with a user_id acts as that user (roles from Config 'auth.user_loader');
 * a service key gets the identity id "api-key:<id>" and only its scopes.
 */
final class ApiKeyAuthenticator implements AuthenticatorInterface
{
    private readonly UserResolver $users;

    /** @param null|callable(int): mixed $userLoader defaults to Config 'auth.user_loader' */
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly string $header = 'X-API-Key',
        ?callable $userLoader = null
    ) {
        $this->users = new UserResolver($userLoader);
    }

    public function authenticate(Request $request, string $guard = 'default'): ?Identity
    {
        $plaintext = trim((string) $request->header($this->header, ''));
        if ($plaintext === '' || strlen($plaintext) > 200) {
            return null;
        }

        $key = $this->keys->verify($plaintext);
        if ($key === null) {
            return null;
        }

        $roles = [];
        if ($key['user_id'] !== null) {
            $roles = $this->users->roles($key['user_id']);
            if ($roles === null) {
                return null;
            }
        }

        return new Identity(
            $key['user_id'] ?? 'api-key:' . $key['id'],
            $roles,
            $key['scopes'],
            ['credential' => 'api_key', 'credential_id' => $key['id'], 'name' => $key['name']]
        );
    }
}
