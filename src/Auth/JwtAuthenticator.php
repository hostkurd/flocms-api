<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;

/**
 * Stateless authentication with HS256 JSON Web Tokens in the Authorization
 * header. Claims used:
 *
 * - `sub` (required): the identity id; numeric subjects are loaded through
 *   Config 'auth.user_loader' when $loadUsers is true
 * - `exp`, `nbf`, `iat`: checked with $leeway seconds of clock skew
 * - `iss`, `aud`: checked when configured
 * - `scope` (space separated) or `scopes` (array), `roles` (array)
 *
 * Issue tokens with Jwt::encode(). Use a secret of at least 32 random bytes,
 * kept out of the repository (e.g. API_JWT_SECRET in .env).
 */
final class JwtAuthenticator implements AuthenticatorInterface
{
    private readonly UserResolver $users;

    /** @param null|callable(int): mixed $userLoader defaults to Config 'auth.user_loader' */
    public function __construct(
        private readonly string $secret,
        private readonly ?string $issuer = null,
        private readonly ?string $audience = null,
        private readonly int $leeway = 30,
        private readonly bool $loadUsers = true,
        ?callable $userLoader = null
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The JWT secret must be at least 32 bytes.');
        }
        $this->users = new UserResolver($userLoader);
    }

    public function authenticate(Request $request, string $guard = 'default'): ?Identity
    {
        $token = $request->bearerToken();
        if ($token === null || substr_count($token, '.') !== 2) {
            return null;
        }

        $claims = Jwt::decode($token, $this->secret, $this->leeway);
        if ($claims === null || !$this->claimsAccepted($claims)) {
            return null;
        }

        $subject = $claims['sub'];
        $roles = is_array($claims['roles'] ?? null) ? array_values(array_map('strval', $claims['roles'])) : [];
        if ($this->loadUsers && $this->users->enabled() && ctype_digit((string) $subject)) {
            $roles = $this->users->roles((int) $subject);
            if ($roles === null) {
                return null;
            }
            $subject = (int) $subject;
        }

        return new Identity($subject, $roles, $this->scopes($claims), ['credential' => 'jwt', 'claims' => $claims]);
    }

    /** @param array<string, mixed> $claims */
    private function claimsAccepted(array $claims): bool
    {
        $subject = $claims['sub'] ?? null;
        if ((!is_string($subject) && !is_int($subject)) || $subject === '') {
            return false;
        }
        if ($this->issuer !== null && ($claims['iss'] ?? null) !== $this->issuer) {
            return false;
        }
        if ($this->audience !== null) {
            $audience = $claims['aud'] ?? null;
            $audiences = is_array($audience) ? $audience : [$audience];
            if (!in_array($this->audience, $audiences, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private function scopes(array $claims): array
    {
        if (is_array($claims['scopes'] ?? null)) {
            return array_values(array_filter($claims['scopes'], 'is_string'));
        }
        if (is_string($claims['scope'] ?? null)) {
            return array_values(array_filter(explode(' ', $claims['scope'])));
        }

        return [];
    }
}
