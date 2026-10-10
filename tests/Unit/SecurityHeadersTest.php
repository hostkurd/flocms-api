<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\SecurityHeadersMiddleware;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecurityHeadersTest extends TestCase
{
    use MakesRequests;

    public function testKernelFallbackAddsSecurityHeaders(): void
    {
        $router = new Router();
        $router->get('/v1/boom', static fn () => throw new RuntimeException('boom'));
        $kernel = (new Kernel($router))->middleware([]);

        foreach (['/v1/boom' => 500, '/v1/missing' => 404] as $path => $status) {
            $response = $kernel->handle($this->request('GET', $path));

            self::assertSame($status, $response->status());
            foreach (SecurityHeadersMiddleware::HEADERS as $name => $value) {
                self::assertSame($value, $response->headers()[$name] ?? null, $name . ' on ' . $path);
            }
        }
    }

    public function testSecurityHeadersOutermostCoverErrorResponses(): void
    {
        $router = new Router();
        $kernel = (new Kernel($router))->middleware([
            new SecurityHeadersMiddleware(),
            new ExceptionMiddleware(),
        ]);

        $response = $kernel->handle($this->request('GET', '/v1/missing'));

        self::assertSame(404, $response->status());
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null);
    }

    public function testFallbackKeepsHeadersAlreadySet(): void
    {
        $router = new Router();
        $kernel = (new Kernel($router))->middleware([
            static function ($request, $next): Response {
                throw new \FloCMS\Api\Exceptions\ApiException('Gone.', 410, [], ['Cache-Control' => 'public, max-age=60']);
            },
        ]);

        $response = $kernel->handle($this->request('GET', '/v1/x'));

        self::assertSame(410, $response->status());
        self::assertSame('public, max-age=60', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
    }
}
