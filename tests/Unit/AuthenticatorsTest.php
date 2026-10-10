<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Auth\FirstMatchAuthenticator;
use FloCMS\Api\Auth\Jwt;
use FloCMS\Api\Auth\JwtAuthenticator;
use FloCMS\Api\Auth\SessionAuthenticator;
use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Config;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuthenticatorsTest extends TestCase
{
    use MakesRequests;

    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    protected function tearDown(): void
    {
        Config::$settings = [];
        $_SESSION = [];
    }

    public function testJwtRoundTrip(): void
    {
        $token = Jwt::encode(['sub' => 'svc-1', 'exp' => time() + 60, 'scope' => 'a b'], self::SECRET);
        $identity = (new JwtAuthenticator(self::SECRET))->authenticate(
            $this->request('GET', '/', ['Authorization' => 'Bearer ' . $token])
        );

        self::assertSame('svc-1', $identity?->id);
        self::assertSame(['a', 'b'], $identity->scopes);
    }

    public function testJwtRejectsTamperingAndOtherAlgorithms(): void
    {
        $token = Jwt::encode(['sub' => '1', 'admin' => false], self::SECRET);
        [$header, $payload, $signature] = explode('.', $token);
        $b64 = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $forgedPayload = $header . '.' . $b64('{"sub":"1","admin":true}') . '.' . $signature;
        $none = $b64('{"alg":"none","typ":"JWT"}') . '.' . $payload . '.';
        $hs512Header = $b64('{"alg":"HS512","typ":"JWT"}');
        $hs512 = $hs512Header . '.' . $payload . '.' . $b64(hash_hmac('sha256', $hs512Header . '.' . $payload, self::SECRET, true));

        self::assertNotNull(Jwt::decode($token, self::SECRET));
        self::assertNull(Jwt::decode($forgedPayload, self::SECRET));
        self::assertNull(Jwt::decode($none, self::SECRET));
        self::assertNull(Jwt::decode($hs512, self::SECRET));
        self::assertNull(Jwt::decode($token, self::SECRET . 'x'));
        self::assertNull(Jwt::decode('a.b', self::SECRET));
        self::assertNull(Jwt::decode($header . '.' . $payload . '.' . $signature . '=', self::SECRET));
    }

    public function testJwtTimeClaims(): void
    {
        $now = 1_800_000_000;
        $decode = static fn (array $claims): ?array => Jwt::decode(Jwt::encode($claims, self::SECRET), self::SECRET, 30, $now);

        self::assertNull($decode(['sub' => '1', 'exp' => $now - 31]));
        self::assertNotNull($decode(['sub' => '1', 'exp' => $now - 29]));
        self::assertNull($decode(['sub' => '1', 'nbf' => $now + 31]));
        self::assertNull($decode(['sub' => '1', 'iat' => $now + 31]));
        self::assertNull($decode(['sub' => '1', 'exp' => (string) ($now + 100)]));
    }

    public function testJwtIssuerAudienceAndUsers(): void
    {
        Config::set('auth.user_loader', static fn (int $id) => $id === 4 ? ['role' => 2, 'status' => 1] : null);
        $authenticator = new JwtAuthenticator(self::SECRET, issuer: 'https://example.com', audience: 'api');
        $authenticate = fn (array $claims): ?Identity => $authenticator->authenticate(
            $this->request('GET', '/', ['Authorization' => 'Bearer ' . Jwt::encode($claims, self::SECRET)])
        );

        $identity = $authenticate(['sub' => '4', 'iss' => 'https://example.com', 'aud' => ['api', 'web']]);
        self::assertSame(4, $identity?->id);
        self::assertSame(['2'], $identity->roles);
        self::assertNull($authenticate(['sub' => '5', 'iss' => 'https://example.com', 'aud' => 'api']), 'deleted user');
        self::assertNull($authenticate(['sub' => '4', 'iss' => 'https://evil.test', 'aud' => 'api']));
        self::assertNull($authenticate(['sub' => '4', 'iss' => 'https://example.com', 'aud' => 'other']));
        self::assertNull($authenticate(['iss' => 'https://example.com', 'aud' => 'api']), 'missing sub');
    }

    public function testJwtSecretMustBeLong(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JwtAuthenticator('short');
    }

    public function testSessionAuthenticatorUsesAdminSession(): void
    {
        $authenticator = $this->sessionAuthenticator([
            'isloggedin' => true,
            'user_id' => '3',
            'role' => '2',
            'admin_access' => true,
            '_csrf_token' => 'csrf-123',
        ]);

        $identity = $authenticator->authenticate($this->request('GET', '/v1/me'));
        self::assertSame(3, $identity?->id);
        self::assertSame(['2'], $identity->roles);
        self::assertSame('session', $identity->data['credential']);

        self::assertNotNull($authenticator->authenticate($this->request('POST', '/v1/pages', ['X-CSRF-TOKEN' => 'csrf-123'])));

        try {
            $authenticator->authenticate($this->request('POST', '/v1/pages', ['X-CSRF-TOKEN' => 'wrong']));
            self::fail('Expected a CSRF failure.');
        } catch (ApiException $error) {
            self::assertSame(419, $error->status);
        }
    }

    public function testSessionAuthenticatorRejectsNonAdminsAndGuests(): void
    {
        $member = ['isloggedin' => true, 'user_id' => '9', 'role' => '0', 'admin_access' => false];

        self::assertNull($this->sessionAuthenticator($member)->authenticate($this->request('GET', '/')));
        self::assertNotNull($this->sessionAuthenticator($member, requireAdmin: false)->authenticate($this->request('GET', '/')));
        self::assertNull($this->sessionAuthenticator([])->authenticate($this->request('GET', '/')));
        self::assertNull((new SessionAuthenticator(sessionStarter: static fn (): bool => false))->authenticate($this->request('GET', '/')));
    }

    public function testSessionAuthenticatorReloadsTheUser(): void
    {
        Config::set('auth.user_loader', static fn (int $id) => ['role' => 2, 'status' => 0]);

        $identity = $this->sessionAuthenticator([
            'isloggedin' => true,
            'user_id' => '3',
            'role' => '2',
            'admin_access' => true,
        ])->authenticate($this->request('GET', '/'));

        self::assertNull($identity, 'suspended users lose access');
    }

    public function testSessionWithoutCookieIsNotStarted(): void
    {
        self::assertNull((new SessionAuthenticator())->authenticate($this->request('GET', '/')));
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testFirstMatchAuthenticator(): void
    {
        $none = new class implements AuthenticatorInterface {
            public function authenticate(Request $request, string $guard = 'default'): ?Identity
            {
                return null;
            }
        };
        $fixed = new class implements AuthenticatorInterface {
            public function authenticate(Request $request, string $guard = 'default'): ?Identity
            {
                return new Identity('fixed');
            }
        };

        self::assertSame('fixed', (new FirstMatchAuthenticator($none, $fixed))->authenticate($this->request('GET', '/'))?->id);
        self::assertNull((new FirstMatchAuthenticator($none))->authenticate($this->request('GET', '/')));
    }

    public function testIdentityPermissions(): void
    {
        Config::set('permissions', [1 => ['content.*'], 2 => ['users.manage'], 3 => ['*']]);

        self::assertTrue((new Identity(1, ['1']))->can('content.pages.edit'));
        self::assertFalse((new Identity(1, ['1']))->can('contentx'));
        self::assertTrue((new Identity(1, ['1', '2']))->can('users.manage'));
        self::assertTrue((new Identity(1, ['3']))->can('anything'));
        self::assertFalse((new Identity(1, []))->can('content.x'));
        self::assertTrue((new Identity(1, [], [], [], ['reports.view']))->can('reports.view'));
        self::assertFalse((new Identity(1, ['3'], [], [], []))->can('anything'), 'explicit list overrides roles');

        $this->expectException(ApiException::class);
        (new Identity(1, ['1']))->authorize('users.manage');
    }

    /** @param array<string, mixed> $session */
    private function sessionAuthenticator(array $session, bool $requireAdmin = true): SessionAuthenticator
    {
        return new SessionAuthenticator($requireAdmin, static function () use ($session): bool {
            $_SESSION = $session;

            return true;
        });
    }
}
