<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\CorsMiddleware;
use FloCMS\Api\Middleware\Deprecated;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VersioningTest extends TestCase
{
    use MakesRequests;

    public function testVersionGroupPrefixesPathsAndNames(): void
    {
        $router = new Router();
        $router->version('v1', static function (Router $router): void {
            $router->get('/properties', static fn (): array => ['v' => 1])->name('properties.index');
        });
        $router->version('2', static function (Router $router): void {
            $router->get('/properties', static fn (): array => ['v' => 2])->name('properties.index');
        });

        self::assertSame('/v1/properties', $router->url('v1.properties.index'));
        self::assertSame('/v2/properties', $router->url('v2.properties.index'));
        self::assertSame(2, $this->payload((new Kernel($router))->handle($this->request('GET', '/v2/properties')))['data']['v']);
    }

    public function testInvalidVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Router())->version('../v1', static fn () => null);
    }

    public function testDeprecationHeadersOnSuccessAndErrors(): void
    {
        $router = new Router();
        $router->version('v1', static function (Router $router): void {
            $router->get('/properties', static fn (): array => []);
            $router->get('/properties/{id:\d+}', static fn () => throw new ApiException('Property not found.', 404));
        }, [new Deprecated('2026-10-01', '2027-06-30', 'https://example.com/docs/v1', 'https://example.com/api/v2')]);
        $kernel = (new Kernel($router))->middleware([new ExceptionMiddleware(), new CorsMiddleware(['https://app.test'])]);

        foreach (['/v1/properties' => 200, '/v1/properties/5' => 404] as $path => $status) {
            $response = $kernel->handle($this->request('GET', $path, ['Origin' => 'https://app.test']));
            $headers = $response->headers();

            self::assertSame($status, $response->status());
            self::assertSame('@' . strtotime('2026-10-01 00:00:00 UTC'), $headers['Deprecation']);
            self::assertSame('Wed, 30 Jun 2027 00:00:00 GMT', $headers['Sunset']);
            self::assertSame('<https://example.com/docs/v1>; rel="deprecation", <https://example.com/api/v2>; rel="successor-version"', $headers['Link']);
            self::assertSame('https://app.test', $headers['Access-Control-Allow-Origin'] ?? null);
        }
    }

    public function testDeprecationWithoutDates(): void
    {
        $router = new Router();
        $router->get('/old', static fn (): array => [])->middleware(new Deprecated());

        $headers = (new Kernel($router))->handle($this->request('GET', '/old'))->headers();

        self::assertSame('true', $headers['Deprecation']);
        self::assertArrayNotHasKey('Sunset', $headers);
    }

    public function testInvalidDeprecationInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Deprecated(sunset: 'not a date at all');
    }
}
