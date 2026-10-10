<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\CacheControl;
use FloCMS\Api\Middleware\ETagMiddleware;
use FloCMS\Api\Middleware\SecurityHeadersMiddleware;
use FloCMS\Api\ResponseFactory;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpCacheTest extends TestCase
{
    use MakesRequests;

    public function testPublicCacheOnSuccessfulGet(): void
    {
        $headers = $this->kernel()->handle($this->request('GET', '/v1/pages'))->headers();

        self::assertSame('public, max-age=300, s-maxage=600', $headers['Cache-Control']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testAuthenticatedRequestsAreCachedPrivately(): void
    {
        foreach (['Authorization' => 'Bearer x', 'X-API-Key' => 'flk_1_x'] as $name => $value) {
            $headers = $this->kernel()->handle($this->request('GET', '/v1/pages', [$name => $value]))->headers();
            self::assertSame('private, max-age=300', $headers['Cache-Control'], $name);
        }
    }

    public function testErrorsAndWritesKeepNoStore(): void
    {
        $kernel = $this->kernel();

        self::assertSame('no-store', $kernel->handle($this->request('GET', '/v1/missing-page'))->headers()['Cache-Control']);
        self::assertSame('no-store', $kernel->handle($this->request('POST', '/v1/pages'))->headers()['Cache-Control']);
    }

    public function testHandlerCachePolicyWins(): void
    {
        $router = new Router();
        $router->get('/v1/x', static fn () => Response::json([])->header('Cache-Control', 'no-cache'))
            ->middleware(CacheControl::public(60));

        self::assertSame('no-cache', (new Kernel($router))->handle($this->request('GET', '/v1/x'))->headers()['Cache-Control']);
    }

    public function testEtagAnd304(): void
    {
        $kernel = $this->kernel();
        $first = $kernel->handle($this->request('GET', '/v1/pages'));
        $etag = $first->headers()['ETag'];

        self::assertMatchesRegularExpression('/^W\/"[0-9a-f]{64}"$/', $etag);

        $cached = $kernel->handle($this->request('GET', '/v1/pages', ['If-None-Match' => '"other", ' . substr($etag, 2)]));
        self::assertSame(304, $cached->status());
        self::assertSame('', $cached->body());
        self::assertSame($etag, $cached->headers()['ETag']);
        self::assertSame('public, max-age=300, s-maxage=600', $cached->headers()['Cache-Control']);
        self::assertArrayNotHasKey('Content-Type', $cached->headers());

        $changed = $kernel->handle($this->request('GET', '/v1/pages', ['If-None-Match' => '"stale"']));
        self::assertSame(200, $changed->status());

        self::assertSame(304, $kernel->handle($this->request('HEAD', '/v1/pages', ['If-None-Match' => '*']))->status());
    }

    public function testLastModified(): void
    {
        $kernel = $this->kernel();
        $response = $kernel->handle($this->request('GET', '/v1/pages'));

        self::assertSame('Thu, 01 Oct 2026 09:30:00 GMT', $response->headers()['Last-Modified']);
        self::assertSame(304, $kernel->handle($this->request('GET', '/v1/pages', ['If-Modified-Since' => 'Thu, 01 Oct 2026 09:30:00 GMT']))->status());
        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/pages', ['If-Modified-Since' => 'Wed, 30 Sep 2026 00:00:00 GMT']))->status());
        // If-None-Match takes precedence over If-Modified-Since.
        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/pages', [
            'If-None-Match' => '"stale"',
            'If-Modified-Since' => 'Thu, 01 Oct 2026 09:30:00 GMT',
        ]))->status());
    }

    public function testNoEtagForErrorsOrWrites(): void
    {
        $kernel = $this->kernel();

        self::assertArrayNotHasKey('ETag', $kernel->handle($this->request('POST', '/v1/pages'))->headers());
    }

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->get('/v1/pages', static fn () => ResponseFactory::withLastModified(
            ResponseFactory::success([['id' => 1, 'title' => 'About']]),
            '2026-10-01 09:30:00 UTC'
        ))->middleware(CacheControl::public(300, 600), new ETagMiddleware());
        $router->post('/v1/pages', static fn () => ResponseFactory::success(['id' => 2], 201))
            ->middleware(CacheControl::public(300), new ETagMiddleware());

        return (new Kernel($router))->middleware([new SecurityHeadersMiddleware()]);
    }
}
