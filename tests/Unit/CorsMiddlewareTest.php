<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\CorsMiddleware;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorsMiddlewareTest extends TestCase
{
    use MakesRequests;

    public function testSimpleResponseCarriesOnlyOriginExposeAndVary(): void
    {
        $headers = $this->kernel(['https://app.example.com'])
            ->handle($this->request('GET', '/v1/items', ['Origin' => 'https://app.example.com']))
            ->headers();

        self::assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $headers['Vary']);
        self::assertStringContainsString('X-RateLimit-Reset', $headers['Access-Control-Expose-Headers']);
        self::assertStringContainsString('Retry-After', $headers['Access-Control-Expose-Headers']);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Headers', $headers);
        self::assertArrayNotHasKey('Access-Control-Max-Age', $headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }

    public function testPreflightEchoesOnlyAllowedRequestedHeaders(): void
    {
        $response = $this->kernel(['https://app.example.com'], credentials: true)->handle(
            $this->request('OPTIONS', '/v1/items', [
                'Origin' => 'https://app.example.com',
                'Access-Control-Request-Method' => 'post',
                'Access-Control-Request-Headers' => 'content-type, x-csrf-token',
            ])
        );
        $headers = $response->headers();

        self::assertSame(204, $response->status());
        self::assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        self::assertSame('Content-Type, X-CSRF-TOKEN', $headers['Access-Control-Allow-Headers']);
        self::assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $headers['Access-Control-Allow-Methods']);
        self::assertSame('86400', $headers['Access-Control-Max-Age']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame('Origin, Access-Control-Request-Method, Access-Control-Request-Headers', $headers['Vary']);
        self::assertArrayNotHasKey('Access-Control-Expose-Headers', $headers);
    }

    public function testPreflightWithoutRequestedHeadersOmitsAllowHeaders(): void
    {
        $headers = $this->kernel(['https://app.example.com'])->handle(
            $this->request('OPTIONS', '/v1/items', [
                'Origin' => 'https://app.example.com',
                'Access-Control-Request-Method' => 'GET',
            ])
        )->headers();

        self::assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Headers', $headers);
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function rejectedPreflights(): iterable
    {
        yield 'method not allowed' => [['Access-Control-Request-Method' => 'TRACE']];
        yield 'header not allowed' => [[
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, X-Secret',
        ]];
        yield 'origin not allowed' => [[
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'GET',
        ]];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('rejectedPreflights')]
    public function testRejectedPreflightHasNoAllowOrigin(array $headers): void
    {
        $response = $this->kernel(['https://app.example.com'])->handle(
            $this->request('OPTIONS', '/v1/items', $headers + ['Origin' => 'https://app.example.com'])
        );

        self::assertSame(204, $response->status());
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers());
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function patternOrigins(): iterable
    {
        yield 'one label' => ['https://app.example.com', true];
        yield 'upper case' => ['https://APP.Example.com', true];
        yield 'apex' => ['https://example.com', false];
        yield 'two labels' => ['https://a.b.example.com', false];
        yield 'other scheme' => ['http://app.example.com', false];
        yield 'suffix attack' => ['https://app.example.com.evil.test', false];
        yield 'prefix attack' => ['https://appexample.com', false];
        yield 'other port' => ['https://app.example.com:8443', false];
        yield 'empty label' => ['https://.example.com', false];
    }

    #[DataProvider('patternOrigins')]
    public function testOriginPatternMatchesExactlyOneLabel(string $origin, bool $allowed): void
    {
        $headers = $this->kernel(['https://*.example.com'])
            ->handle($this->request('GET', '/v1/items', ['Origin' => $origin]))
            ->headers();

        self::assertSame($allowed ? $origin : null, $headers['Access-Control-Allow-Origin'] ?? null);
    }

    public function testOriginPatternWithPort(): void
    {
        $headers = $this->kernel(['http://*.localhost:5173'])
            ->handle($this->request('GET', '/v1/items', ['Origin' => 'http://shop.localhost:5173']))
            ->headers();

        self::assertSame('http://shop.localhost:5173', $headers['Access-Control-Allow-Origin'] ?? null);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidPatterns(): iterable
    {
        yield 'wildcard in the middle' => ['https://app.*.example.com'];
        yield 'bare wildcard label' => ['https://*'];
        yield 'wildcard tld' => ['https://*.com'];
        yield 'no scheme' => ['*.example.com'];
        yield 'partial label' => ['https://app-*.example.com'];
    }

    #[DataProvider('invalidPatterns')]
    public function testInvalidPatternsAreRejected(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsMiddleware([$pattern]);
    }

    public function testWildcardOrigin(): void
    {
        $headers = $this->kernel(['*'])
            ->handle($this->request('GET', '/v1/items', ['Origin' => 'https://anything.test']))
            ->headers();

        self::assertSame('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testExistingVaryIsKept(): void
    {
        $router = new Router();
        $router->get('/v1/items', static fn () => Response::json([])->header('Vary', 'Accept-Language'));
        $kernel = (new Kernel($router))->middleware([new CorsMiddleware(['https://app.example.com'])]);

        $headers = $kernel->handle(
            $this->request('GET', '/v1/items', ['Origin' => 'https://app.example.com'])
        )->headers();

        self::assertSame('Accept-Language, Origin', $headers['Vary']);
    }

    /** @param list<string> $origins */
    private function kernel(array $origins, bool $credentials = false): Kernel
    {
        $router = new Router();
        $router->get('/v1/items', static fn (): array => []);
        $router->post('/v1/items', static fn (): array => []);

        return (new Kernel($router))->middleware([new CorsMiddleware($origins, $credentials)]);
    }
}
