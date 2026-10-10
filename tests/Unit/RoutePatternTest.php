<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Route;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoutePatternTest extends TestCase
{
    public function testParameterWithoutConstraintMatchesOneSegment(): void
    {
        $route = new Route(['GET'], '/v1/properties/{slug}', static fn () => null);

        self::assertSame(['slug' => 'sea-villa'], $route->matchPath('/v1/properties/sea-villa'));
        self::assertSame(['slug' => 'a b'], $route->matchPath('/v1/properties/a%20b'));
        self::assertNull($route->matchPath('/v1/properties/sea/villa'));
        self::assertNull($route->matchPath('/v1/properties'));
    }

    public function testParameterWithConstraint(): void
    {
        $route = new Route(['GET'], '/v1/widgets/{id:\d+}', static fn () => null);

        self::assertSame(['id' => '42'], $route->matchPath('/v1/widgets/42'));
        self::assertNull($route->matchPath('/v1/widgets/abc'));
    }

    public function testSeveralUnconstrainedParameters(): void
    {
        $route = new Route(['GET'], '/v1/{a}/{b}', static fn () => null);

        self::assertSame(['a' => 'x', 'b' => 'y'], $route->matchPath('/v1/x/y'));
        self::assertNull($route->matchPath('/v1/x'));
    }

    public function testConstraintSpanningSegmentsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route(['GET'], '/v1/files/{path:.+/.+}', static fn () => null);
    }

    public function testEmptyConstraintIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route(['GET'], '/v1/files/{path:}', static fn () => null);
    }

    public function testApiWithUnconstrainedRouteStillServesOtherRoutes(): void
    {
        $router = new Router();
        $router->get('/v1/health', static fn (): array => ['status' => 'ok']);
        $router->get('/v1/properties/{slug}', static fn (string $slug): array => ['slug' => $slug]);
        $kernel = new Kernel($router);

        $health = $kernel->handle($this->request('/v1/health'));
        $property = $kernel->handle($this->request('/v1/properties/sea-villa'));

        self::assertSame(200, $health->status());
        self::assertSame(200, $property->status());
        self::assertSame(
            'sea-villa',
            json_decode($property->body(), true, 512, JSON_THROW_ON_ERROR)['data']['slug']
        );
    }

    private function request(string $uri): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], []);
    }
}
