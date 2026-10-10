<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\JsonBodyMiddleware;
use FloCMS\Api\RouteLoader;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Support\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouterTest extends TestCase
{
    use MakesRequests;

    public function testNestedGroupsMergePrefixMiddlewareAndNames(): void
    {
        $trace = [];
        $tag = static function (string $name) use (&$trace): \Closure {
            return static function (Request $request, $next) use ($name, &$trace): Response {
                $trace[] = $name;

                return $next->handle($request);
            };
        };
        $router = new Router();
        $router->group('/api', static function (Router $router) use ($tag): void {
            $router->group('v1/', static function (Router $router) use ($tag): void {
                $router->get('//items/', static fn (): array => [])->name('items')->middleware($tag('route'));
            }, [$tag('inner')], namePrefix: 'v1.');
        }, [$tag('outer')], namePrefix: 'api.');

        $route = $router->routes()[0];
        self::assertSame('/api/v1/items', $route->pattern());
        self::assertSame('api.v1.items', $route->name());

        (new Kernel($router))->handle($this->request('GET', '/api/v1/items/'));
        self::assertSame(['outer', 'inner', 'route'], $trace);
    }

    public function testAutomaticHeadAndOptions(): void
    {
        $router = new Router();
        $router->get('/v1/items', static fn (): array => ['a' => 1]);
        $router->post('/v1/items', static fn (): array => []);
        $kernel = new Kernel($router);

        $head = $kernel->handle($this->request('HEAD', '/v1/items'));
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());

        $options = $kernel->handle($this->request('OPTIONS', '/v1/items'));
        self::assertSame(204, $options->status());
        self::assertSame('GET, HEAD, OPTIONS, POST', $options->headers()['Allow']);

        self::assertSame(404, $kernel->handle($this->request('OPTIONS', '/v1/none'))->status());
        self::assertSame('GET, HEAD, OPTIONS, POST', $kernel->handle($this->request('PUT', '/v1/items'))->headers()['Allow']);
    }

    public function testFirstMatchWins(): void
    {
        $router = new Router();
        $router->get('/v1/p/{id:\d+}', static fn (int $id): array => ['by' => 'id']);
        $router->get('/v1/p/{slug}', static fn (string $slug): array => ['by' => 'slug']);
        $kernel = new Kernel($router);

        self::assertSame('id', $this->payload($kernel->handle($this->request('GET', '/v1/p/12')))['data']['by']);
        self::assertSame('slug', $this->payload($kernel->handle($this->request('GET', '/v1/p/sea-villa')))['data']['by']);
    }

    public function testRouteLoaderDefinitionList(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'flo-routes') . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            return [
                ['method' => 'GET', 'path' => '/v1/list', 'handler' => static fn (): array => ['ok' => true], 'name' => 'list'],
                ['methods' => ['PUT', 'PATCH'], 'path' => '/v1/list/{id}', 'handler' => static fn (int $id): array => ['id' => $id], 'middleware' => [new FloCMS\Api\Middleware\JsonBodyMiddleware()]],
            ];
            PHP);
        $router = new Router();
        (new RouteLoader($router, new Container()))->load($file);
        unlink($file);

        self::assertSame('list', $router->routes()[0]->name());
        self::assertSame(['PUT', 'PATCH'], $router->routes()[1]->methods());
        self::assertInstanceOf(JsonBodyMiddleware::class, $router->routes()[1]->middlewareStack()[0]);
        self::assertSame(3, $this->payload((new Kernel($router))->handle($this->request('PATCH', '/v1/list/3')))['data']['id']);
    }

    public function testRouteLoaderRejectsBadFiles(): void
    {
        $this->expectException(RuntimeException::class);

        (new RouteLoader(new Router(), new Container()))->load('/nonexistent/routes.php');
    }

    public function testJsonBodyLimitAndClassStringMiddleware(): void
    {
        $router = new Router();
        $router->post('/v1/x', static fn (): array => [])->middleware(JsonBodyMiddleware::class);
        $kernel = (new Kernel($router))->middleware([new JsonBodyMiddleware(10)]);

        self::assertSame(413, $kernel->handle($this->request('POST', '/v1/x', ['Content-Type' => 'application/json'], '{"a":"0123456789"}'))->status());
        self::assertSame(200, $kernel->handle($this->request('POST', '/v1/x', ['Content-Type' => 'application/json'], '{}'))->status());
        self::assertSame(400, $kernel->handle($this->request('POST', '/v1/x', ['Content-Type' => 'application/vnd.api+json'], '{'))->status());
    }

    public function testCallableMiddlewareMustReturnAResponse(): void
    {
        $router = new Router();
        $router->get('/v1/x', static fn (): array => [])->middleware(static fn () => 'not a response');

        self::assertSame(500, (new Kernel($router))->handle($this->request('GET', '/v1/x'))->status());
    }
}
