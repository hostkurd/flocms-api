<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Support\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KernelArgumentsTest extends TestCase
{
    use MakesRequests;

    /** @return iterable<string, array{0: string, 1: int, 2: mixed}> */
    public static function castCases(): iterable
    {
        yield 'int' => ['/int/42', 200, 42];
        yield 'negative int' => ['/int/-7', 200, -7];
        yield 'int rejects text' => ['/int/abc', 422, null];
        yield 'int rejects float' => ['/int/1.5', 422, null];
        yield 'float' => ['/float/2.5', 200, 2.5];
        yield 'float rejects text' => ['/float/x', 422, null];
        yield 'bool true' => ['/bool/yes', 200, true];
        yield 'bool false' => ['/bool/0', 200, false];
        yield 'bool rejects text' => ['/bool/maybe', 422, null];
        yield 'string decoded' => ['/string/a%20b', 200, 'a b'];
    }

    #[DataProvider('castCases')]
    public function testRouteParametersAreCastToHandlerTypes(string $uri, int $status, mixed $value): void
    {
        $response = $this->kernel()->handle($this->request('GET', $uri));

        self::assertSame($status, $response->status(), $response->body());
        if ($status === 200) {
            self::assertSame($value, $this->payload($response)['data']['value']);
        }
    }

    public function testRequestDependencyInjectionDefaultsAndNullables(): void
    {
        $container = new Container();
        $container->instance(Greeter::class, new Greeter('Hello'));
        $router = new Router();
        $router->get('/greet/{name}', static fn (Greeter $greeter, Request $request, string $name, int $times = 2, ?string $missing = null): array => [
            'text' => $greeter->greet($name),
            'times' => $times,
            'missing' => $missing,
            'path' => $request->path(),
        ]);
        $router->get('/named-request', static fn ($request): array => ['path' => $request->path()]);

        $kernel = new Kernel($router, $container);
        $data = $this->payload($kernel->handle($this->request('GET', '/greet/Sara')))['data'];

        self::assertSame(['text' => 'Hello, Sara', 'times' => 2, 'missing' => null, 'path' => '/greet/Sara'], $data);
        self::assertSame('/named-request', $this->payload($kernel->handle($this->request('GET', '/named-request')))['data']['path']);
    }

    public function testHandlerForms(): void
    {
        $router = new Router();
        $router->get('/array', [WidgetsController::class, 'show']);
        $router->get('/at', WidgetsController::class . '@show');
        $router->get('/invokable', InvokableHandler::class);
        $router->get('/object', new InvokableHandler());
        $router->get('/null', static fn () => null);
        $router->get('/response', static fn (): Response => Response::text('plain', 202));
        $kernel = new Kernel($router);

        foreach (['/array', '/at'] as $path) {
            self::assertSame('widget', $this->payload($kernel->handle($this->request('GET', $path)))['data']['name']);
        }
        foreach (['/invokable', '/object'] as $path) {
            self::assertSame('invoked', $this->payload($kernel->handle($this->request('GET', $path)))['data']['by']);
        }
        self::assertSame(204, $kernel->handle($this->request('GET', '/null'))->status());
        self::assertSame(202, $kernel->handle($this->request('GET', '/response'))->status());
    }

    public function testUnresolvableParameterIsA500(): void
    {
        $router = new Router();
        $router->get('/broken', static fn (int $count): array => []);

        self::assertSame(500, (new Kernel($router))->handle($this->request('GET', '/broken'))->status());
    }

    public function testHeadStripsBodyAndKeepsHeaders(): void
    {
        $response = $this->kernel()->handle($this->request('HEAD', '/int/5'));

        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
    }

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->get('/int/{value}', static fn (int $value): array => ['value' => $value]);
        $router->get('/float/{value}', static fn (float $value): array => ['value' => $value]);
        $router->get('/bool/{value}', static fn (bool $value): array => ['value' => $value]);
        $router->get('/string/{value}', static fn (string $value): array => ['value' => $value]);

        return new Kernel($router);
    }
}

final class Greeter
{
    public function __construct(private readonly string $greeting)
    {
    }

    public function greet(string $name): string
    {
        return $this->greeting . ', ' . $name;
    }
}

final class WidgetsController
{
    /** @return array<string, string> */
    public function show(): array
    {
        return ['name' => 'widget'];
    }
}

final class InvokableHandler
{
    /** @return array<string, string> */
    public function __invoke(): array
    {
        return ['by' => 'invoked'];
    }
}
