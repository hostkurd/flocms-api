<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\CorsMiddleware;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\RateLimitMiddleware;
use FloCMS\Core\RateLimit\FileRateLimiter;
use FloCMS\Api\Router;
use FloCMS\Core\Security\ClientIpResolver;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase
{
    use MakesRequests;

    private const ORIGIN = 'https://app.example.com';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/flo-api-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testPreflightsAreAnsweredByCorsAndNotRateLimited(): void
    {
        $kernel = $this->kernel(limit: 2);
        $preflight = $this->request('OPTIONS', '/v1/inquiries', [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $response = $kernel->handle($preflight);
            self::assertSame(204, $response->status(), 'preflight #' . $i);
            self::assertSame(self::ORIGIN, $response->headers()['Access-Control-Allow-Origin'] ?? null);
            self::assertArrayNotHasKey('X-RateLimit-Remaining', $response->headers());
        }

        // The actual requests still get their full budget.
        $post = $this->request('POST', '/v1/inquiries', ['Origin' => self::ORIGIN]);
        self::assertSame('1', $kernel->handle($post)->headers()['X-RateLimit-Remaining']);
        self::assertSame('0', $kernel->handle($post)->headers()['X-RateLimit-Remaining']);

        $limited = $kernel->handle($post);
        self::assertSame(429, $limited->status());
        self::assertSame(self::ORIGIN, $limited->headers()['Access-Control-Allow-Origin'] ?? null);
    }

    public function testPreflightDoesNotRunTheHandler(): void
    {
        $called = false;
        $router = new Router();
        $router->options('/v1/inquiries', static function () use (&$called) {
            $called = true;

            return ['options' => true];
        });
        $kernel = (new Kernel($router))->middleware([new CorsMiddleware([self::ORIGIN])]);

        $response = $kernel->handle($this->request('OPTIONS', '/v1/inquiries', [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
        ]));

        self::assertSame(204, $response->status());
        self::assertFalse($called);
    }

    public function testPlainOptionsStillReachesTheKernel(): void
    {
        $response = $this->kernel(limit: 1)->handle($this->request('OPTIONS', '/v1/inquiries'));

        self::assertSame(204, $response->status());
        self::assertSame('OPTIONS, POST', $response->headers()['Allow']);
    }

    public function testPlainOptionsIsNotRateLimited(): void
    {
        $kernel = $this->kernel(limit: 1);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(204, $kernel->handle($this->request('OPTIONS', '/v1/inquiries'))->status());
        }
    }

    private function kernel(int $limit): Kernel
    {
        $router = new Router();
        $router->post('/v1/inquiries', static fn (): array => ['ok' => true]);

        return (new Kernel($router))->middleware([
            new ExceptionMiddleware(),
            new CorsMiddleware([self::ORIGIN]),
            new RateLimitMiddleware(
                new FileRateLimiter($this->directory),
                new ClientIpResolver(),
                'api',
                $limit,
                60
            ),
        ]);
    }
}
