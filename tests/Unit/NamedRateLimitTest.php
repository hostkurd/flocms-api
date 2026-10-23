<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\MiddlewareAliases;
use FloCMS\Core\RateLimit\ApcuRateLimiter;
use FloCMS\Core\RateLimit\FileRateLimiter;
use FloCMS\Api\RateLimit\RateLimiterRegistry;
use FloCMS\Api\Router;
use FloCMS\Core\Security\ClientIpResolver;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NamedRateLimitTest extends TestCase
{
    use MakesRequests;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/flo-api-rl-' . bin2hex(random_bytes(6));
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

    public function testThrottleAliasAppliesNamedLimitPerRoute(): void
    {
        $kernel = $this->kernel();

        self::assertSame('1', $kernel->handle($this->request('POST', '/v1/inquiries'))->headers()['X-RateLimit-Remaining']);
        self::assertSame('0', $kernel->handle($this->request('POST', '/v1/inquiries'))->headers()['X-RateLimit-Remaining']);
        $limited = $kernel->handle($this->request('POST', '/v1/inquiries'));
        self::assertSame(429, $limited->status());
        self::assertArrayHasKey('Retry-After', $limited->headers());

        // Another named limit has its own budget.
        self::assertSame('59', $kernel->handle($this->request('GET', '/v1/pages'))->headers()['X-RateLimit-Remaining']);
        // Another IP has its own budget.
        self::assertSame(200, $kernel->handle($this->request('POST', '/v1/inquiries', remoteAddr: '10.0.0.9'))->status());
    }

    public function testAuthenticatedRequestsAreKeyedByIdentity(): void
    {
        $authenticate = static fn (Request $request, $next): Response => $next->handle(
            $request->withAttribute('identity', new Identity((int) $request->header('X-User')))
        );
        $kernel = $this->kernel($authenticate);
        $asUser = fn (string $id): Request => $this->request('POST', '/v1/inquiries', ['X-User' => $id]);

        $kernel->handle($asUser('1'));
        $kernel->handle($asUser('1'));
        self::assertSame(429, $kernel->handle($asUser('1'))->status());
        // Same IP, different user: separate bucket.
        self::assertSame(200, $kernel->handle($asUser('2'))->status());
    }

    public function testUnknownLimitNameFailsLoudly(): void
    {
        $registry = new RateLimiterRegistry(new FileRateLimiter($this->directory), new ClientIpResolver());

        $this->expectException(InvalidArgumentException::class);
        $registry->middleware('missing');
    }

    public function testInvalidLimitDefinitionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimiterRegistry(new FileRateLimiter($this->directory), new ClientIpResolver(), ['forms' => [0, 60]]);
    }

    public function testUnknownAliasIsAnInvalidMiddleware(): void
    {
        $router = new Router();
        $router->get('/v1/x', static fn (): array => [])->middleware('nope:1');
        $response = (new Kernel($router))->aliases(new MiddlewareAliases())->handle($this->request('GET', '/v1/x'));

        self::assertSame(500, $response->status());
    }

    public function testAliasArguments(): void
    {
        $seen = [];
        $aliases = new MiddlewareAliases([
            'tag' => static function (string ...$args) use (&$seen) {
                $seen = $args;

                return static fn (Request $request, $next): Response => $next->handle($request);
            },
        ]);
        $router = new Router();
        $router->get('/v1/x', static fn (): array => [])->middleware('tag:a, b');
        (new Kernel($router))->aliases($aliases)->handle($this->request('GET', '/v1/x'));

        self::assertSame(['a', 'b'], $seen);
    }

    public function testGcRemovesOnlyStaleStateFiles(): void
    {
        $limiter = new FileRateLimiter($this->directory, gcProbability: 0);
        $limiter->hit('fresh', 5, 60);
        $limiter->hit('stale', 5, 60);
        $stale = $this->directory . '/' . hash('sha256', 'stale') . '.json';
        touch($stale, time() - 90_000);
        file_put_contents($this->directory . '/keep-me.txt', 'x');
        touch($this->directory . '/keep-me.txt', time() - 90_000);

        self::assertSame(1, $limiter->gc());
        self::assertFileDoesNotExist($stale);
        self::assertFileExists($this->directory . '/' . hash('sha256', 'fresh') . '.json');
        self::assertFileExists($this->directory . '/keep-me.txt');
    }

    public function testProbabilisticGcRunsOnHit(): void
    {
        $limiter = new FileRateLimiter($this->directory, gcProbability: 1, gcMaxAge: 60);
        $limiter->hit('stale', 5, 60);
        $stale = $this->directory . '/' . hash('sha256', 'stale') . '.json';
        touch($stale, time() - 120);

        $limiter->hit('other', 5, 60);

        self::assertFileDoesNotExist($stale);
    }

    public function testApcuLimiterWhenAvailable(): void
    {
        if (!ApcuRateLimiter::isAvailable()) {
            self::markTestSkipped('APCu is not enabled (apc.enable_cli=1 is needed on the CLI).');
        }

        $limiter = new ApcuRateLimiter('flo-test-' . bin2hex(random_bytes(4)) . ':');
        self::assertTrue($limiter->hit('k', 2, 60)->allowed);
        self::assertTrue($limiter->hit('k', 2, 60)->allowed);
        self::assertFalse($limiter->hit('k', 2, 60)->allowed);
        $limiter->clear('k');
        self::assertTrue($limiter->hit('k', 2, 60)->allowed);
    }

    private function kernel(?callable $authenticate = null): Kernel
    {
        $limiters = new RateLimiterRegistry(
            new FileRateLimiter($this->directory),
            new ClientIpResolver(),
            ['forms' => [2, 60], 'public' => [60, 60]]
        );
        $router = new Router();
        $inquiry = $router->post('/v1/inquiries', static fn (): array => ['ok' => true]);
        if ($authenticate !== null) {
            $inquiry->middleware($authenticate);
        }
        $inquiry->middleware('throttle:forms');
        $router->get('/v1/pages', static fn (): array => [])->middleware('throttle');

        return (new Kernel($router))
            ->aliases(['throttle' => $limiters->aliasFactory()])
            ->middleware([new ExceptionMiddleware()]);
    }
}
