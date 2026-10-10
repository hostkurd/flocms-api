<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Auth\ApiKeyAuthenticator;
use FloCMS\Api\Auth\ApiKeyRepository;
use FloCMS\Api\Auth\BearerTokenAuthenticator;
use FloCMS\Api\Auth\TokenRepository;
use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Database\Connection;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\AuthenticateMiddleware;
use FloCMS\Api\Router;
use FloCMS\Api\Security\Identity;
use FloCMS\Api\Tests\Support\Databases;
use FloCMS\Api\Tests\Unit\MakesRequests;
use FloCMS\Core\Config;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    use MakesRequests;

    protected function tearDown(): void
    {
        Config::$settings = [];
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testApiKeyLifecycle(string $driver): void
    {
        $db = Databases::connect($driver);
        $keys = new ApiKeyRepository($db);

        $issued = $keys->create('Partner feed', ['properties.read', 'properties.read']);
        self::assertMatchesRegularExpression('/^flk_' . $issued->id . '_[0-9a-f]{40}$/', $issued->plaintext);

        $stored = $db->first('SELECT * FROM api_keys WHERE id = ?', [$issued->id]);
        self::assertSame(hash('sha256', substr($issued->plaintext, -40)), $stored['secret_hash']);
        self::assertStringNotContainsString(substr($issued->plaintext, -40), json_encode($stored));

        $key = $keys->verify($issued->plaintext);
        self::assertSame($issued->id, $key['id']);
        self::assertSame(['properties.read'], $key['scopes']);
        self::assertArrayNotHasKey('secret_hash', $key);
        self::assertNotNull($db->first('SELECT last_used_at FROM api_keys WHERE id = ?', [$issued->id])['last_used_at']);

        self::assertNull($keys->verify(substr($issued->plaintext, 0, -1) . (str_ends_with($issued->plaintext, '0') ? '1' : '0')));
        self::assertNull($keys->verify('flk_999_' . str_repeat('a', 40)));
        self::assertNull($keys->verify('nonsense'));
        self::assertNull($keys->verify(str_replace('flk_', 'flt_', $issued->plaintext)));

        self::assertTrue($keys->revoke($issued->id));
        self::assertFalse($keys->revoke($issued->id));
        self::assertNull($keys->verify($issued->plaintext));
        self::assertNotNull($keys->find($issued->id)['revoked_at']);
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testExpiredKeyIsRejected(string $driver): void
    {
        $db = Databases::connect($driver);
        $keys = new ApiKeyRepository($db);

        $expired = $keys->create('Old', [], null, '2020-01-01');
        $future = $keys->create('New', [], null, date('Y-m-d', strtotime('+1 year')));

        self::assertNull($keys->verify($expired->plaintext));
        self::assertNotNull($keys->verify($future->plaintext));
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testInvalidInputIsRejected(string $driver): void
    {
        $keys = new ApiKeyRepository(Databases::connect($driver));

        foreach ([['', []], ['ok', ['bad scope!']], ['ok', [], '01/02/2030']] as $arguments) {
            try {
                $keys->create($arguments[0], $arguments[1], null, $arguments[2] ?? null);
                self::fail('Expected InvalidArgumentException for ' . json_encode($arguments));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testApiKeyAuthenticatorProtectsRoutes(string $driver): void
    {
        $db = Databases::connect($driver);
        $keys = new ApiKeyRepository($db);
        $reader = $keys->create('Reader', ['properties.read']);
        $kernel = $this->kernel(new ApiKeyAuthenticator($keys), ['properties.read']);

        self::assertSame(401, $kernel->handle($this->request('GET', '/v1/me'))->status());
        self::assertSame(401, $kernel->handle($this->request('GET', '/v1/me', ['X-API-Key' => 'flk_1_bad']))->status());

        $response = $kernel->handle($this->request('GET', '/v1/me', ['X-API-Key' => $reader->plaintext]));
        self::assertSame(200, $response->status());
        self::assertSame('api-key:' . $reader->id, $this->payload($response)['data']['id']);

        $other = $keys->create('Other', ['pages.read']);
        self::assertSame(403, $kernel->handle($this->request('GET', '/v1/me', ['X-API-Key' => $other->plaintext]))->status());
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testUserKeysFollowTheUserLoader(string $driver): void
    {
        $users = [7 => ['role' => 1, 'status' => 1], 8 => ['role' => 3, 'status' => 0]];
        Config::set('auth.user_loader', static fn (int $id) => $users[$id] ?? null);
        Config::set('permissions', [1 => ['content.*'], 3 => ['*']]);
        $keys = new ApiKeyRepository(Databases::connect($driver));
        $editor = $keys->create('Editor key', [], 7);
        $suspended = $keys->create('Suspended key', [], 8);
        $deleted = $keys->create('Deleted user key', [], 9);
        $authenticator = new ApiKeyAuthenticator($keys);

        $identity = $authenticator->authenticate($this->request('GET', '/', ['X-API-Key' => $editor->plaintext]));
        self::assertSame(7, $identity?->id);
        self::assertTrue($identity->can('content.pages.edit'));
        self::assertFalse($identity->can('users.manage'));

        self::assertNull($authenticator->authenticate($this->request('GET', '/', ['X-API-Key' => $suspended->plaintext])));
        self::assertNull($authenticator->authenticate($this->request('GET', '/', ['X-API-Key' => $deleted->plaintext])));
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testPermissionMiddleware(string $driver): void
    {
        Config::set('auth.user_loader', static fn (int $id) => ['role' => $id === 1 ? 2 : 0, 'status' => 1]);
        Config::set('permissions', [0 => [], 2 => ['users.manage']]);
        $tokens = new TokenRepository(Databases::connect($driver));
        $admin = $tokens->issue(1, 'Admin app');
        $user = $tokens->issue(2, 'User app');
        $router = new Router();
        $router->get('/v1/users', static fn (): array => [])
            ->middleware(new AuthenticateMiddleware(new BearerTokenAuthenticator($tokens), permissions: ['users.manage']));
        $kernel = new Kernel($router);

        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/users', ['Authorization' => 'Bearer ' . $admin->plaintext]))->status());
        self::assertSame(403, $kernel->handle($this->request('GET', '/v1/users', ['Authorization' => 'Bearer ' . $user->plaintext]))->status());
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testTokenLifecycle(string $driver): void
    {
        $tokens = new TokenRepository(Databases::connect($driver));
        $phone = $tokens->issue(5, 'Phone', ['inquiries.write']);
        $laptop = $tokens->issueFor(5, 'Laptop', 3600);
        $other = $tokens->issue(6, 'Other');
        $authenticator = new BearerTokenAuthenticator($tokens, static fn (int $id) => ['role' => 0, 'status' => 1]);

        self::assertMatchesRegularExpression('/^flt_\d+_[0-9a-f]{40}$/', $phone->plaintext);
        $identity = $authenticator->authenticate($this->request('GET', '/', ['Authorization' => 'Bearer ' . $phone->plaintext]));
        self::assertSame(5, $identity?->id);
        self::assertSame(['inquiries.write'], $identity->scopes);
        self::assertSame('token', $identity->data['credential']);

        // Hosts that strip Authorization can use X-FloCMS-Authorization.
        self::assertNotNull($authenticator->authenticate($this->request('GET', '/', ['X-FloCMS-Authorization' => 'Bearer ' . $laptop->plaintext])));
        self::assertNull($authenticator->authenticate($this->request('GET', '/', ['Authorization' => 'Bearer ' . str_replace('flt_', 'flk_', $phone->plaintext)])));

        self::assertCount(2, $tokens->forUser(5));
        self::assertSame(2, $tokens->revokeAllForUser(5));
        self::assertNull($tokens->verify($phone->plaintext));
        self::assertNull($tokens->verify($laptop->plaintext));
        self::assertNotNull($tokens->verify($other->plaintext));
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testLastUsedIsWrittenAtMostOncePerMinute(string $driver): void
    {
        $db = Databases::connect($driver);
        $keys = new ApiKeyRepository($db);
        $issued = $keys->create('Busy');
        $recent = Connection::now(-10);
        $db->execute('UPDATE api_keys SET last_used_at = ? WHERE id = ?', [$recent, $issued->id]);

        $keys->verify($issued->plaintext);
        self::assertSame($recent, $db->first('SELECT last_used_at FROM api_keys WHERE id = ?', [$issued->id])['last_used_at']);

        $db->execute('UPDATE api_keys SET last_used_at = ? WHERE id = ?', [Connection::now(-120), $issued->id]);
        $keys->verify($issued->plaintext);
        self::assertGreaterThan(Connection::now(-5), $db->first('SELECT last_used_at FROM api_keys WHERE id = ?', [$issued->id])['last_used_at']);
    }

    /** @param list<string> $scopes */
    private function kernel(AuthenticatorInterface $authenticator, array $scopes = []): Kernel
    {
        $router = new Router();
        $router->get('/v1/me', static fn (Request $request): array => [
            'id' => $request->attribute('identity')->id,
        ])->middleware(new AuthenticateMiddleware($authenticator, scopes: $scopes));

        return new Kernel($router);
    }
}
