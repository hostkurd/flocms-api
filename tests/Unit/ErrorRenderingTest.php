<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\JsonBodyMiddleware;
use FloCMS\Api\Router;
use FloCMS\Core\HttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorRenderingTest extends TestCase
{
    use MakesRequests;

    /** @return iterable<string, array{0: list<mixed>}> */
    public static function middlewareLists(): iterable
    {
        yield 'default list' => [null];
        yield 'replaced list without ExceptionMiddleware' => [[new JsonBodyMiddleware()]];
        yield 'empty list' => [[]];
    }

    /** @param list<mixed>|null $middleware */
    #[DataProvider('middlewareLists')]
    public function testClientErrorsKeepTheirStatus(?array $middleware): void
    {
        $router = new Router();
        $router->get('/v1/widgets', static fn (): array => []);
        $router->post('/v1/invalid', static fn () => throw new ValidationException(['name' => ['Required.']]));
        $router->get('/v1/limited', static fn () => throw new ApiException('Slow down.', 429, [], ['Retry-After' => '30']));
        $router->get('/v1/forbidden', static fn () => throw new HttpException(403));
        $kernel = new Kernel($router);
        if ($middleware !== null) {
            $kernel->middleware($middleware);
        }

        self::assertSame(404, $kernel->handle($this->request('GET', '/v1/missing'))->status());

        $notAllowed = $kernel->handle($this->request('DELETE', '/v1/widgets'));
        self::assertSame(405, $notAllowed->status());
        self::assertSame('GET, HEAD, OPTIONS', $notAllowed->headers()['Allow']);

        $invalid = $kernel->handle($this->request('POST', '/v1/invalid'));
        self::assertSame(422, $invalid->status());
        self::assertSame(['name' => ['Required.']], $this->payload($invalid)['errors']);

        $limited = $kernel->handle($this->request('GET', '/v1/limited'));
        self::assertSame(429, $limited->status());
        self::assertSame('30', $limited->headers()['Retry-After']);

        $forbidden = $kernel->handle($this->request('GET', '/v1/forbidden'));
        self::assertSame(403, $forbidden->status());
        self::assertSame('Forbidden.', $this->payload($forbidden)['message']);
    }

    public function testUnexpectedErrorsAreGeneric500InProduction(): void
    {
        $router = new Router();
        $router->get('/v1/boom', static fn () => throw new RuntimeException('secret detail'));
        $kernel = (new Kernel($router))->middleware([]);

        $response = $kernel->handle($this->request('GET', '/v1/boom'));
        $payload = $this->payload($response);

        self::assertSame(500, $response->status());
        self::assertSame('Internal Server Error', $payload['message']);
        self::assertArrayNotHasKey('errors', $payload);
        self::assertSame($response->headers()['X-Request-ID'], $payload['request_id']);
    }

    public function testUnexpectedErrorsShowDetailInDebug(): void
    {
        $router = new Router();
        $router->get('/v1/boom', static fn () => throw new RuntimeException('secret detail'));

        foreach ([[], [new ExceptionMiddleware(debug: true)]] as $middleware) {
            $kernel = (new Kernel($router, debug: true))->middleware($middleware);
            $payload = $this->payload($kernel->handle($this->request('GET', '/v1/boom')));

            self::assertSame('secret detail', $payload['message']);
            self::assertSame(RuntimeException::class, $payload['errors']['exception']);
        }
    }

    public function testServerErrorDetailsAreHiddenInProduction(): void
    {
        $router = new Router();
        $router->get('/v1/down', static fn () => throw new ApiException('Upstream failed.', 503, ['dsn' => 'mysql://…']));
        $kernel = new Kernel($router);

        $payload = $this->payload($kernel->handle($this->request('GET', '/v1/down')));

        self::assertSame('Upstream failed.', $payload['message']);
        self::assertArrayNotHasKey('errors', $payload);
    }
}
