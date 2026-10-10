<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\BodySizeMiddleware;
use FloCMS\Api\Middleware\RequireJsonMiddleware;
use FloCMS\Api\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BodyMiddlewareTest extends TestCase
{
    use MakesRequests;

    /** @return iterable<string, array{0: array<string, string>, 1: string, 2: int}> */
    public static function bodies(): iterable
    {
        yield 'small json' => [['Content-Type' => 'application/json', 'Content-Length' => '9'], '{"a":"b"}', 200];
        yield 'declared too large' => [['Content-Type' => 'multipart/form-data', 'Content-Length' => '11'], '', 413];
        yield 'actual too large' => [['Content-Type' => 'text/plain'], str_repeat('x', 11), 413];
        yield 'huge declared length' => [['Content-Length' => '99999999999999999999999'], '', 413];
        yield 'invalid length' => [['Content-Length' => '10abc'], '', 400];
        yield 'exactly the limit' => [['Content-Type' => 'text/plain', 'Content-Length' => '10'], str_repeat('x', 10), 200];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('bodies')]
    public function testBodySizeLimit(array $headers, string $body, int $status): void
    {
        $kernel = $this->kernel(new BodySizeMiddleware(10));

        $response = $kernel->handle($this->request('POST', '/v1/items', $headers, $body));

        self::assertSame($status, $response->status());
        if ($status === 413) {
            self::assertSame(10, $this->payload($response)['errors']['max_bytes']);
        }
    }

    /** @return iterable<string, array{0: string, 1: array<string, string>, 2: string, 3: bool, 4: int}> */
    public static function contentTypes(): iterable
    {
        yield 'json' => ['POST', ['Content-Type' => 'application/json; charset=utf-8'], '{}', false, 200];
        yield 'json suffix' => ['PATCH', ['Content-Type' => 'application/merge-patch+json'], '{}', false, 200];
        yield 'form' => ['POST', ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=b', false, 415];
        yield 'multipart refused' => ['POST', ['Content-Type' => 'multipart/form-data; boundary=x', 'Content-Length' => '120'], '', false, 415];
        yield 'multipart allowed' => ['POST', ['Content-Type' => 'multipart/form-data; boundary=x', 'Content-Length' => '120'], '', true, 200];
        yield 'no body' => ['POST', [], '', false, 200];
        yield 'zero length' => ['PUT', ['Content-Length' => '0'], '', false, 200];
        yield 'get ignored' => ['GET', ['Content-Type' => 'text/plain'], 'x', false, 200];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('contentTypes')]
    public function testRequireJson(string $method, array $headers, string $body, bool $multipart, int $status): void
    {
        $kernel = $this->kernel(new RequireJsonMiddleware(allowMultipart: $multipart));

        self::assertSame($status, $kernel->handle($this->request($method, '/v1/items', $headers, $body))->status());
    }

    private function kernel(object $middleware): Kernel
    {
        $router = new Router();
        $router->map(['GET', 'POST', 'PUT', 'PATCH'], '/v1/items', static fn (): array => ['ok' => true]);

        return (new Kernel($router))->middleware([$middleware]);
    }
}
