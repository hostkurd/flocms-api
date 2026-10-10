<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\AuthenticateMiddleware;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\JsonBodyMiddleware;
use FloCMS\Api\Router;
use FloCMS\Api\Security\Identity;
use FloCMS\Api\Testing\StaticAuthenticator;
use FloCMS\Api\Testing\TestClient;
use FloCMS\Core\Http\Request;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class TestClientTest extends TestCase
{
    public function testFluentAssertions(): void
    {
        $client = new TestClient($this->kernel());

        $client->get('/v1/properties', ['status' => 'sale'])
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=utf-8')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('data.0.title', 'Sea villa')
            ->assertJsonPath('meta.status', 'sale')
            ->assertJsonMissingPath('data.0.secret')
            ->assertJsonCount(2);

        $client->postJson('/v1/inquiries', ['name' => 'Sara'])->assertValidationError('email', 'required');
        $client->postJson('/v1/inquiries', ['email' => 'sara@example.com'])
            ->assertCreated()
            ->assertHeader('Location', '/v1/inquiries/1')
            ->assertJsonPath('data.email', 'sara@example.com');
        $client->get('/v1/nope')->assertNotFound();
        $client->delete('/v1/properties')->assertStatus(405);
    }

    public function testHeadersTokensAndIp(): void
    {
        $client = (new TestClient($this->kernel()))->withToken('abc')->fromIp('203.0.113.9')->withHeader('X-Extra', '1');

        $client->get('/v1/echo')
            ->assertJsonPath('data.authorization', 'Bearer abc')
            ->assertJsonPath('data.ip', '203.0.113.9')
            ->assertJsonPath('data.extra', '1');

        // The original client is unchanged.
        (new TestClient($this->kernel()))->get('/v1/echo')->assertJsonPath('data.authorization', null);
    }

    public function testStaticAuthenticator(): void
    {
        $router = new Router();
        $router->get('/v1/me', static fn (Request $request): array => ['id' => $request->attribute('identity')->id])
            ->middleware(new AuthenticateMiddleware(new StaticAuthenticator(new Identity(42))));
        $router->get('/v1/guest', static fn (): array => [])
            ->middleware(new AuthenticateMiddleware(new StaticAuthenticator(null)));
        $client = new TestClient(new Kernel($router));

        $client->get('/v1/me')->assertOk()->assertJsonPath('data.id', 42);
        $client->get('/v1/guest')->assertUnauthorized();
    }

    public function testFailingAssertionsFailTheTest(): void
    {
        $this->expectException(AssertionFailedError::class);

        (new TestClient($this->kernel()))->get('/v1/nope')->assertOk();
    }

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->get('/v1/properties', static fn (Request $request): \FloCMS\Core\Http\Response => \FloCMS\Api\ResponseFactory::success(
            [['title' => 'Sea villa'], ['title' => 'River house']],
            200,
            ['status' => $request->queryValue('status')]
        ));
        $router->post('/v1/inquiries', static function (Request $request) {
            if (!$request->input('email')) {
                throw new ValidationException(['email' => ['email is required.']]);
            }

            return \FloCMS\Api\ResponseFactory::success(['email' => $request->input('email')], 201)
                ->header('Location', '/v1/inquiries/1');
        });
        $router->get('/v1/echo', static fn (Request $request): array => [
            'authorization' => $request->header('Authorization'),
            'ip' => $request->server('REMOTE_ADDR'),
            'extra' => $request->header('X-Extra'),
        ]);

        return (new Kernel($router))->middleware([new ExceptionMiddleware(), new JsonBodyMiddleware()]);
    }
}
