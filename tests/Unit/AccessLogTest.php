<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\AccessLogMiddleware;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Router;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class AccessLogTest extends TestCase
{
    use MakesRequests;

    public function testPsrLoggerReceivesOneEntryWithoutSecrets(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $router = new Router();
        $router->post('/v1/login', static fn (): array => ['token' => 'secret-token']);
        $kernel = (new Kernel($router))->middleware([
            new AccessLogMiddleware($logger),
            new ExceptionMiddleware(),
        ]);

        $request = $this->request(
            'POST',
            '/v1/login?password=hunter2',
            ['Authorization' => 'Bearer abc', 'Content-Type' => 'application/json'],
            '{"password":"hunter2"}',
            '203.0.113.5'
        );
        $response = $kernel->handle($request);

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame('info', $level);
        self::assertSame('POST', $context['method']);
        self::assertSame('/v1/login', $context['path']);
        self::assertSame(200, $context['status']);
        self::assertSame('203.0.113.5', $context['ip']);
        self::assertSame($response->headers()['X-Request-ID'], $context['request_id']);
        self::assertIsFloat($context['duration_ms']);
        $serialized = json_encode($logger->records);
        self::assertStringNotContainsString('hunter2', $serialized);
        self::assertStringNotContainsString('secret-token', $serialized);
        self::assertStringNotContainsString('Bearer', $serialized);
    }

    public function testFileTargetLogsErrorStatuses(): void
    {
        $file = sys_get_temp_dir() . '/flo-api-log-' . bin2hex(random_bytes(4)) . '/api.log';
        $router = new Router();
        $router->get('/v1/boom', static fn () => throw new RuntimeException('x'));
        $kernel = (new Kernel($router))->middleware([new AccessLogMiddleware($file)]);

        $kernel->handle($this->request('GET', '/v1/boom'));
        $kernel->handle($this->request('GET', "/v1/missing\nfake 200"));

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        unlink($file);
        rmdir(dirname($file));

        self::assertCount(2, $lines);
        self::assertMatchesRegularExpression('~^\[[0-9: -]+\] GET /v1/boom 500 [0-9.]+ms request_id=[0-9a-f]{32} ip=127\.0\.0\.1$~', $lines[0]);
        self::assertStringContainsString(' 404 ', $lines[1]);
    }
}
