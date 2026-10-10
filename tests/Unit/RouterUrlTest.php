<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterUrlTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->group('/flocms/api', static function (Router $router): void {
            $router->group('/v1', static function (Router $router): void {
                $router->get('/properties', static fn (): array => [])->name('properties.index');
                $router->get('/properties/{slug}', static fn (): array => [])->name('properties.show');
                $router->get('/agents/{id:\d+}/listings/{page:\d+}', static fn (): array => [])->name('agents.listings');
            }, namePrefix: 'v1.');
        });
        $router->get('/', static fn (): array => [])->name('root');

        return $router;
    }

    public function testUrls(): void
    {
        $router = $this->router();

        self::assertSame('/flocms/api/v1/properties', $router->url('v1.properties.index'));
        self::assertSame(
            '/flocms/api/v1/properties?page=2&filter%5Bstatus%5D=sale',
            $router->url('v1.properties.index', [], ['page' => 2, 'filter' => ['status' => 'sale']])
        );
        self::assertSame('/flocms/api/v1/properties/sea-villa', $router->url('v1.properties.show', ['slug' => 'sea-villa']));
        self::assertSame('/flocms/api/v1/properties/a%20b%3F%2F', $router->url('v1.properties.show', ['slug' => 'a b?/']));
        self::assertSame('/flocms/api/v1/agents/4/listings/2', $router->url('v1.agents.listings', ['id' => 4, 'page' => '2']));
        self::assertSame('/', $router->url('root'));
    }

    public function testGeneratedUrlsRouteBack(): void
    {
        $router = $this->router();
        $url = $router->url('v1.properties.show', ['slug' => 'a b?/ü'], ['x' => 1]);
        $request = new \FloCMS\Core\Http\Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $url], [], []);

        $match = $router->match($request);

        self::assertSame('v1.properties.show', $match->route->name());
        self::assertSame(['slug' => 'a b?/ü'], $match->params);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'unknown name' => ['nope', []];
        yield 'missing parameter' => ['v1.properties.show', []];
        yield 'empty parameter' => ['v1.properties.show', ['slug' => '']];
        yield 'constraint mismatch' => ['v1.agents.listings', ['id' => 'abc', 'page' => 1]];
        yield 'unknown parameter' => ['v1.properties.show', ['slug' => 'x', 'sulg' => 'y']];
        yield 'array parameter' => ['v1.properties.show', ['slug' => ['x']]];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('invalid')]
    public function testInvalidUrlRequests(string $name, array $params): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->router()->url($name, $params);
    }
}
