<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\ResponseFactory;
use FloCMS\Api\Router;
use PHPUnit\Framework\TestCase;

final class RequestIdTest extends TestCase
{
    use MakesRequests;

    public function testRequestIdIsInTheHeaderAndOptionallyInSuccessBodies(): void
    {
        $router = new Router();
        $router->get('/v1/x', static fn (): array => ['ok' => true]);
        $router->get('/v1/missing', static fn () => throw new \FloCMS\Api\Exceptions\ApiException('Nope.', 404));

        $plain = (new Kernel($router))->handle($this->request('GET', '/v1/x', ['X-Request-ID' => 'client-req-0001']));
        self::assertSame('client-req-0001', $plain->headers()['X-Request-ID']);
        self::assertArrayNotHasKey('request_id', $this->payload($plain));

        $kernel = (new Kernel($router))->includeRequestIdInBody();
        $success = $kernel->handle($this->request('GET', '/v1/x'));
        self::assertSame($success->headers()['X-Request-ID'], $this->payload($success)['request_id']);

        $error = $kernel->handle($this->request('GET', '/v1/missing'));
        self::assertSame($error->headers()['X-Request-ID'], $this->payload($error)['request_id']);

        // The setting does not leak outside the request.
        self::assertArrayNotHasKey('request_id', $this->payload(ResponseFactory::success([])));
    }

    public function testInvalidIncomingRequestIdIsReplaced(): void
    {
        $router = new Router();
        $router->get('/v1/x', static fn (): array => []);

        $id = (new Kernel($router))->handle($this->request('GET', '/v1/x', ['X-Request-ID' => "bad\nid"]))->headers()['X-Request-ID'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }
}
