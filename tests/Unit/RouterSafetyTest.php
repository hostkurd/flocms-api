<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RouterSafetyTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function shadowedPairs(): iterable
    {
        yield 'renamed parameter' => ['/v1/p/{id}', '/v1/p/{slug}'];
        yield 'constrained after unconstrained' => ['/v1/p/{slug}', '/v1/p/{id:\d+}'];
        yield 'static after parameter' => ['/v1/p/{slug}', '/v1/p/featured'];
        yield 'same constraint' => ['/v1/p/{id:\d+}', '/v1/p/{n:\d+}'];
        yield 'nested' => ['/v1/{a}/{b}', '/v1/x/{id}'];
    }

    #[DataProvider('shadowedPairs')]
    public function testShadowedRouteIsReportedAsWarning(string $first, string $second): void
    {
        $logger = $this->logger();
        $router = new Router($logger);
        $router->get($first, static fn (): array => []);
        $router->get($second, static fn (): array => []);

        self::assertCount(1, $router->warnings());
        self::assertStringContainsString('can never match', $router->warnings()[0]);
        self::assertSame(['warning'], array_column($logger->records, 0));
        self::assertCount(2, $router->routes());
    }

    #[DataProvider('shadowedPairs')]
    public function testShadowedRouteThrowsInStrictMode(string $first, string $second): void
    {
        $router = (new Router())->strict();
        $router->get($first, static fn (): array => []);

        $this->expectException(InvalidArgumentException::class);
        $router->get($second, static fn (): array => []);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function reachablePairs(): iterable
    {
        yield 'constrained first' => ['/v1/p/{id:\d+}', '/v1/p/{slug}'];
        yield 'static first' => ['/v1/p/featured', '/v1/p/{slug}'];
        yield 'different length' => ['/v1/p/{slug}', '/v1/p/{slug}/images'];
        yield 'different constraints' => ['/v1/p/{id:\d+}', '/v1/p/{code:[a-z]+}'];
        yield 'different static' => ['/v1/a/{id}', '/v1/b/{id}'];
    }

    #[DataProvider('reachablePairs')]
    public function testReachableRoutesAreAccepted(string $first, string $second): void
    {
        $router = (new Router())->strict();
        $router->get($first, static fn (): array => []);
        $router->get($second, static fn (): array => []);

        self::assertSame([], $router->warnings());
    }

    public function testDifferentMethodsDoNotShadow(): void
    {
        $router = (new Router())->strict();
        $router->get('/v1/p/{id}', static fn (): array => []);
        $router->delete('/v1/p/{slug}', static fn (): array => []);

        self::assertCount(2, $router->routes());
    }

    public function testDuplicateNameIsAWarningByDefault(): void
    {
        $router = new Router();
        $router->get('/v1/a', static fn (): array => [])->name('items');
        $router->get('/v1/b', static fn (): array => [])->name('items');

        self::assertCount(1, $router->warnings());
        self::assertStringContainsString('Duplicate route name "items"', $router->warnings()[0]);
        self::assertSame('/v1/a', $router->named('items')?->pattern());
    }

    public function testDuplicateNameThrowsInStrictMode(): void
    {
        $router = new Router(strict: true);
        $router->group('/v1', static function (Router $router): void {
            $router->get('/a', static fn (): array => [])->name('items');
        }, namePrefix: 'v1.');
        $router->get('/v2/a', static fn (): array => [])->name('v2.items');

        $this->expectException(InvalidArgumentException::class);
        $router->get('/v1/b', static fn (): array => [])->name('v1.items');
    }

    public function testRenamingTheSameRouteIsAllowed(): void
    {
        $router = (new Router())->strict();
        $route = $router->get('/v1/a', static fn (): array => [])->name('a');
        $route->name('a');

        self::assertSame($route, $router->named('a'));
    }

    public function testExactDuplicatesStillThrowWithoutStrictMode(): void
    {
        $router = new Router();
        $router->get('/v1/a', static fn (): array => []);

        $this->expectException(InvalidArgumentException::class);
        $router->get('/v1/a/', static fn (): array => []);
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }
}
