<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Database\Connection;
use FloCMS\Api\Http\HealthController;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\CorsMiddleware;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\MaintenanceMiddleware;
use FloCMS\Api\Router;
use FloCMS\Core\Security\ClientIpResolver;
use FloCMS\Core\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

final class HealthAndMaintenanceTest extends TestCase
{
    use MakesRequests;

    public function testHealthWithDatabase(): void
    {
        $router = new Router();
        $router->get('/v1/health', new HealthController(connection: new Connection(new PDO('sqlite::memory:'))));

        $response = (new Kernel($router))->handle($this->request('GET', '/v1/health'));

        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'ok', 'db' => 'ok'], $this->payload($response)['data']);
    }

    public function testHealthWhenDatabaseIsDown(): void
    {
        $failing = new Connection(new class('sqlite::memory:') extends PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused to db.internal:3306 as root');
            }
        });
        $router = new Router();
        $router->get('/v1/health', new HealthController(connection: $failing));

        $response = (new Kernel($router))->handle($this->request('GET', '/v1/health'));

        self::assertSame(503, $response->status());
        self::assertSame(['status' => 'fail', 'db' => 'down'], $this->payload($response)['errors']);
        self::assertStringNotContainsString('db.internal', $response->body());
    }

    public function testHealthWithoutDatabaseCheck(): void
    {
        $router = new Router();
        $router->get('/v1/health', new HealthController(checkDatabase: false));

        self::assertSame(['status' => 'ok'], $this->payload((new Kernel($router))->handle($this->request('GET', '/v1/health')))['data']);
    }

    public function testMaintenanceMode(): void
    {
        $down = true;
        $router = new Router();
        $router->get('/v1/pages', static fn (): array => ['ok' => true]);
        $router->get('/v1/health', static fn (): array => ['status' => 'ok']);
        $kernel = (new Kernel($router))->middleware([
            new ExceptionMiddleware(),
            new CorsMiddleware(['https://app.test']),
            new MaintenanceMiddleware(
                static function () use (&$down): bool {
                    return $down;
                },
                ['203.0.113.7', '10.0.0.0/8'],
                120,
                new ClientIpResolver(),
                static fn (Request $request): bool => $request->path() === '/v1/health'
            ),
        ]);

        $blocked = $kernel->handle($this->request('GET', '/v1/pages', ['Origin' => 'https://app.test'], remoteAddr: '198.51.100.1'));
        self::assertSame(503, $blocked->status());
        self::assertSame('120', $blocked->headers()['Retry-After']);
        self::assertSame('https://app.test', $blocked->headers()['Access-Control-Allow-Origin'] ?? null);

        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/pages', remoteAddr: '203.0.113.7'))->status());
        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/pages', remoteAddr: '10.20.30.40'))->status());
        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/health', remoteAddr: '198.51.100.1'))->status());

        $down = false;
        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/pages', remoteAddr: '198.51.100.1'))->status());
    }

    public function testDefaultSwitchIsOffWithoutADatabase(): void
    {
        $router = new Router();
        $router->get('/v1/pages', static fn (): array => []);
        $kernel = (new Kernel($router))->middleware([new MaintenanceMiddleware()]);

        self::assertSame(200, $kernel->handle($this->request('GET', '/v1/pages'))->status());
    }

    public function testInvalidAllowedIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MaintenanceMiddleware(static fn (): bool => true, ['not-an-ip']);
    }
}
