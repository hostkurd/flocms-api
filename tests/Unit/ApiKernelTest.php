<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\JsonBodyMiddleware;
use FloCMS\Api\Router;
use FloCMS\Api\Security\ClientIpResolver;
use FloCMS\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ApiKernelTest extends TestCase
{
    public function testTypedRouteParameterAndResponseEnvelope(): void
    {
        $router = new Router();
        $router->get('/v1/widgets/{id:\\d+}', static fn (int $id): array => ['id' => $id]);
        $response = (new Kernel($router))->handle($this->request('GET', '/v1/widgets/42'));
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status());
        self::assertSame(42, $payload['data']['id']);
        self::assertArrayHasKey('X-Request-ID', $response->headers());
    }

    public function testMethodMismatchReturnsJson405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/v1/widgets', static fn (): array => []);
        $response = (new Kernel($router))->handle($this->request('POST', '/v1/widgets'));

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD, OPTIONS', $response->headers()['Allow']);
    }

    public function testExplicitHeadAndOptionsRoutesTakePrecedence(): void
    {
        $router = new Router();
        $router->get('/v1/status', static fn (): array => ['handler' => 'get']);
        $router->head('/v1/status', static fn () => \FloCMS\Core\Http\Response::text('head', 202));
        $router->options('/v1/status', static fn () => \FloCMS\Core\Http\Response::noContent(200));
        $kernel = new Kernel($router);

        self::assertSame(202, $kernel->handle($this->request('HEAD', '/v1/status'))->status());
        self::assertSame(200, $kernel->handle($this->request('OPTIONS', '/v1/status'))->status());
    }

    public function testMalformedJsonIsRejectedBeforeControllerRuns(): void
    {
        $router = new Router();
        $router->post('/v1/widgets', static fn (): array => ['created' => true]);
        $kernel = (new Kernel($router))->middleware([
            new ExceptionMiddleware(),
            new JsonBodyMiddleware(),
        ]);
        $response = $kernel->handle(
            $this->request('POST', '/v1/widgets', '{"invalid"', 'application/json')
        );

        self::assertSame(400, $response->status());
    }

    public function testTrustedProxyChainIgnoresSpoofedLeftmostAddress(): void
    {
        $request = new Request(
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.66, 198.51.100.9, 10.0.0.1',
            ],
            [],
            []
        );

        self::assertSame(
            '198.51.100.9',
            (new ClientIpResolver(['10.0.0.0/8']))->resolve($request)
        );
    }

    private function request(
        string $method,
        string $uri,
        string $body = '',
        ?string $contentType = null
    ): Request {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        return new Request([], [], $server, [], [], $body);
    }
}
